<?php

namespace App\Models;

use App\Services\CertificateDeployService;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RediNode extends Model
{
    protected static function booted(): void
    {
        static::saving(function (RediNode $node) {
            if ($node->isDirty('public_ip')) {
                $node->public_ip = trim(OpsDisplaySanitizer::stripControlChars((string) $node->public_ip));
            }
            if ($node->isDirty('extra_ips')) {
                $extras = [];
                foreach ((array) $node->extra_ips as $ip) {
                    $ip = trim(OpsDisplaySanitizer::stripControlChars(trim((string) $ip)));
                    if ($ip !== '') {
                        $extras[] = $ip;
                    }
                }
                $node->extra_ips = $extras === [] ? null : array_values($extras);
            }
            if (Schema::hasColumn($node->getTable(), 'public_ipv6') && $node->isDirty('public_ipv6')) {
                $node->public_ipv6 = trim(OpsDisplaySanitizer::stripControlChars((string) $node->public_ipv6));
                if ($node->public_ipv6 === '') {
                    $node->public_ipv6 = null;
                }
            }
            if (! Schema::hasColumn($node->getTable(), 'public_ipv6')) {
                unset($node->public_ipv6);
            }
            if (Schema::hasColumn($node->getTable(), 'extra_ipv6') && $node->isDirty('extra_ipv6')) {
                $extras = [];
                foreach ((array) $node->extra_ipv6 as $ip) {
                    $ip = trim(OpsDisplaySanitizer::stripControlChars(trim((string) $ip)));
                    if ($ip !== '') {
                        $extras[] = $ip;
                    }
                }
                $node->extra_ipv6 = $extras === [] ? null : array_values($extras);
            }
            if (! Schema::hasColumn($node->getTable(), 'extra_ipv6')) {
                unset($node->extra_ipv6);
            }
            if ($node->isDirty('name')) {
                $name = OpsDisplaySanitizer::stripControlChars((string) $node->name);
                $name = trim($name);
                if (mb_strlen($name) > 120) {
                    $name = mb_substr($name, 0, 120);
                }
                $node->name = $name;
            }
            if ($node->isDirty('note')) {
                $note = OpsDisplaySanitizer::stripControlChars((string) $node->note);
                $note = trim($note);
                if ($note === '') {
                    $node->note = null;
                } else {
                    if (mb_strlen($note) > 2000) {
                        $note = mb_substr($note, 0, 2000);
                    }
                    $node->note = $note;
                }
            }
        });

        static::saved(function (RediNode $node) {
            if (! $node->wasChanged('enabled')) {
                return;
            }

            if (! $node->enabled) {
                app(CertificateDeployService::class)->handleNodeDisabled($node);

                return;
            }

            app(CertificateDeployService::class)->syncDeploymentsForNode($node);
        });
    }

    protected $table = 'redi_nodes';

    protected $guarded = [
        'api_token_hash',
    ];

    protected $fillable = [
        'name',
        'public_ip',
        'public_ipv6',
        'extra_ips',
        'extra_ipv6',
        'enabled',
        'last_heartbeat_at',
        'agent_version',
        'note',
    ];

    protected $casts = [
        'extra_ips' => 'array',
        'extra_ipv6' => 'array',
        'enabled' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

    /**
     * 这台机器要写入 pool 的全部公网 IP。主 IP 在前，其余为同一进程上的附加地址。
     *
     * @return string[]
     */
    public function publicIps(): array
    {
        $ips = [];
        $primary = trim((string) $this->public_ip);
        if ($primary !== '' && self::isValidPublicIpv4($primary)) {
            $ips[] = $primary;
        }
        foreach ((array) $this->extra_ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && self::isValidPublicIpv4($ip) && ! in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * @return string[]
     */
    public function publicIpv6s(): array
    {
        if (! Schema::hasColumn($this->getTable(), 'public_ipv6')
            || ! Schema::hasColumn($this->getTable(), 'extra_ipv6')) {
            return [];
        }

        $ips = [];
        $primary = trim((string) $this->public_ipv6);
        if ($primary !== '' && self::isValidPublicIpv6($primary)) {
            $ips[] = self::canonicalPublicIpv6($primary);
        }

        foreach ($this->rawExtraIpv6Items() as $item) {
            $item = trim((string) $item);
            if ($item === '' || ! self::isValidPublicIpv6($item)) {
                continue;
            }
            $canonical = self::canonicalPublicIpv6($item);
            if ($canonical === '' || in_array($canonical, $ips, true)) {
                continue;
            }
            $ips[] = $canonical;
        }

        return $ips;
    }

    public function hasUnreadableIpv6(): bool
    {
        if (! Schema::hasColumn($this->getTable(), 'public_ipv6')
            || ! Schema::hasColumn($this->getTable(), 'extra_ipv6')) {
            return false;
        }

        $primary = trim((string) ($this->getAttributes()['public_ipv6'] ?? ''));
        if ($primary !== '' && ! self::isValidPublicIpv6($primary)) {
            return true;
        }

        foreach ($this->rawExtraIpv6Items() as $item) {
            $item = trim((string) $item);
            if ($item !== '' && ! self::isValidPublicIpv6($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, mixed>
     */
    protected function rawExtraIpv6Items(): array
    {
        $raw = $this->getAttributes()['extra_ipv6'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [$raw];
    }

    public static function isValidPublicIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * @return string 16-byte binary string, or '' when the input is not a plain IPv6 address
     */
    public static function ipv6ToBytes(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (strpbrk($ip, "[]%/\t\n\r ") !== false) {
            return '';
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return '';
        }

        return $packed;
    }

    public static function isValidPublicIpv6(string $ip): bool
    {
        $bytes = self::ipv6ToBytes($ip);
        if ($bytes === '') {
            return false;
        }

        return (ord($bytes[0]) & 0xe0) === 0x20;
    }

    public static function canonicalPublicIpv6(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        $ip = rtrim($ip, '.');
        if ($ip === '') {
            return '';
        }
        if (strpbrk($ip, "[]%/\t\n\r ") !== false) {
            return '';
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return '';
        }
        $text = inet_ntop($packed);

        return $text === false ? '' : strtolower($text);
    }

    /**
     * @param  array<int, mixed>  $ips
     * @return string[]
     */
    public static function filterValidIpv6List(array $ips): array
    {
        $out = [];
        $seen = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '' || ! self::isValidPublicIpv6($ip)) {
                continue;
            }
            $bytes = self::ipv6ToBytes($ip);
            if ($bytes === '' || isset($seen[$bytes])) {
                continue;
            }
            $canonical = self::canonicalPublicIpv6($ip);
            if ($canonical === '') {
                continue;
            }
            $seen[$bytes] = true;
            $out[] = $canonical;
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $ips
     * @return string[]
     */
    public static function filterValidIpv4List(array $ips): array
    {
        $out = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && self::isValidPublicIpv4($ip) && ! in_array($ip, $out, true)) {
                $out[] = $ip;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public static function enabledPublicIps(): array
    {
        $ips = [];
        foreach (static::enabled()->orderBy('id')->get() as $node) {
            foreach ($node->publicIps() as $ip) {
                if (! in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
    }

    /**
     * @return string[]
     */
    public static function enabledPublicIpv6s(): array
    {
        $seen = [];
        $ips = [];
        foreach (static::enabled()->orderBy('id')->get() as $node) {
            foreach ($node->publicIpv6s() as $ip) {
                $bytes = self::ipv6ToBytes($ip);
                if ($bytes === '' || isset($seen[$bytes])) {
                    continue;
                }
                $seen[$bytes] = true;
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * @return string[]
     */
    public static function parseExtraIps(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $ips = [];
        foreach (preg_split('/\s+/', $raw) as $ip) {
            $ip = trim(OpsDisplaySanitizer::stripControlChars(trim($ip)));
            if ($ip === '' || in_array($ip, $ips, true)) {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw ValidationException::withMessages([
                    'extra_ips' => __('redi-admin-node.form.validation.extra_ipv4_invalid', ['value' => $ip]),
                ]);
            }
            $ips[] = $ip;
        }

        return $ips;
    }

    /**
     * @return string[]
     */
    public static function preparePublicIpv6ForStorage(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! self::isValidPublicIpv6($value)) {
            throw ValidationException::withMessages([
                'public_ipv6' => __('redi-admin-node.form.validation.public_ipv6_global'),
            ]);
        }

        return self::canonicalPublicIpv6($value);
    }

    /**
     * @return string[]
     */
    public static function parseExtraIpv6(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $ips = [];
        $seen = [];
        foreach (preg_split('/\s+/', $raw) as $ip) {
            $original = trim((string) $ip);
            $ip = trim(OpsDisplaySanitizer::stripControlChars($original));
            if ($ip === '') {
                continue;
            }
            if (! self::isValidPublicIpv6($ip)) {
                throw ValidationException::withMessages([
                    'extra_ipv6' => __('redi-admin-node.form.validation.extra_ipv6_invalid', ['value' => $original]),
                ]);
            }
            $canonical = self::canonicalPublicIpv6($ip);
            if ($canonical === '') {
                throw ValidationException::withMessages([
                    'extra_ipv6' => __('redi-admin-node.form.validation.extra_ipv6_invalid', ['value' => $original]),
                ]);
            }
            $bytes = self::ipv6ToBytes($ip);
            if ($bytes === '' || isset($seen[$bytes])) {
                continue;
            }
            $seen[$bytes] = true;
            $ips[] = $canonical;
        }

        return $ips;
    }

    /**
     * @param  string[]  $ipv6s
     */
    public static function assertIpv6Available(array $ipv6s, ?int $exceptId = null): void
    {
        if ($ipv6s === []) {
            return;
        }

        $query = static::query();
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $taken = [];
        foreach ($query->get() as $node) {
            foreach ($node->publicIpv6s() as $ip) {
                $bytes = self::ipv6ToBytes($ip);
                if ($bytes !== '') {
                    $taken[$bytes] = self::canonicalPublicIpv6($ip);
                }
            }
        }

        $primary = $ipv6s[0] ?? null;
        foreach ($ipv6s as $ip) {
            $bytes = self::ipv6ToBytes($ip);
            if ($bytes === '') {
                continue;
            }
            if (! isset($taken[$bytes])) {
                continue;
            }
            $canonical = self::canonicalPublicIpv6($ip);
            throw ValidationException::withMessages([
                ($ip === $primary ? 'public_ipv6' : 'extra_ipv6') => __('redi-admin-node.form.validation.ipv6_in_use', ['value' => $canonical]),
            ]);
        }
    }

    /**
     * @param  string[]  $ips
     */
    public static function assertIpsAvailable(array $ips, ?int $exceptId = null): void
    {
        $query = static::query();
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $taken = [];
        foreach ($query->get() as $node) {
            foreach ($node->publicIps() as $ip) {
                $taken[$ip] = true;
            }
        }
        $primary = $ips[0] ?? null;
        foreach ($ips as $ip) {
            if (isset($taken[$ip])) {
                throw ValidationException::withMessages([
                    ($ip === $primary ? 'public_ip' : 'extra_ips') => __('redi-admin-node.form.validation.ipv4_in_use', ['value' => $ip]),
                ]);
            }
        }
    }

    public static function generateApiToken(): string
    {
        return Str::random(48);
    }

    public function issueApiTokenPlain(): string
    {
        $secret = Str::random(48);
        $plain = $this->id.'.'.$secret;
        $this->setApiTokenPlain($plain);

        return $plain;
    }

    public function setApiTokenPlain(string $plain): void
    {
        $this->api_token_hash = Hash::make($plain);
    }

    public function verifyApiToken(string $plain): bool
    {
        return $this->api_token_hash && Hash::check($plain, $this->api_token_hash);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
