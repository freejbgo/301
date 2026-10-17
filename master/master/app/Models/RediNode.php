<?php

namespace App\Models;

use App\Services\CertificateDeployService;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
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
                RediCertDeployment::where('node_id', $node->id)->delete();
                CertificateDeployService::forgetActionableDeploymentMetrics();

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
        'extra_ips',
        'enabled',
        'last_heartbeat_at',
        'agent_version',
        'note',
    ];

    protected $casts = [
        'extra_ips' => 'array',
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

    public static function isValidPublicIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
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
                    'extra_ips' => '请填写 IPv4，一行一个。无效：'.$ip,
                ]);
            }
            $ips[] = $ip;
        }

        return $ips;
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
                    ($ip === $primary ? 'public_ip' : 'extra_ips') => 'IP 已被其他节点使用：'.$ip,
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
