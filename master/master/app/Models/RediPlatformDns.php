<?php

namespace App\Models;

use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class RediPlatformDns extends Model
{
    protected $table = 'redi_platform_dns';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'edge_zone',
        'pool_host',
        'dns_mode',
        'dns_driver',
        'dns_zone_id',
        'acme_contact_email',
        'acme_staging',
        'publish_pool_aaaa',
        'record_ttl',
    ];

    protected $guarded = [
        'api_credentials',
    ];

    protected $casts = [
        'acme_staging' => 'boolean',
        'publish_pool_aaaa' => 'boolean',
        'record_ttl' => 'integer',
    ];

    public static function instance(): self
    {
        $attrs = [
            'edge_zone' => '',
            'pool_host' => 'pool',
            'dns_mode' => 'manual',
            'dns_driver' => 'manual',
        ];
        if (static::hasPublishPoolAaaaColumn()) {
            $attrs['publish_pool_aaaa'] = true;
        }
        if (static::hasRecordTtlColumn()) {
            $attrs['record_ttl'] = 600;
        }

        return static::firstOrCreate(['id' => 1], $attrs);
    }

    /**
     * 后台「DNS 与节点池」开关。列还没迁移时才看 REDI_PUBLISH_POOL_AAAA。
     */
    public static function publishesPoolAaaa(): bool
    {
        if (! static::hasPublishPoolAaaaColumn()) {
            return filter_var(config('redi.publish_pool_aaaa', false), FILTER_VALIDATE_BOOLEAN);
        }

        $row = static::query()->where('id', 1)->first();
        if (! $row) {
            return true;
        }

        return (bool) $row->publish_pool_aaaa;
    }

    public static function hasPublishPoolAaaaColumn(): bool
    {
        return Schema::hasTable('redi_platform_dns')
            && Schema::hasColumn('redi_platform_dns', 'publish_pool_aaaa');
    }

    /**
     * 同步写入 DNS 记录时使用的 TTL（秒）。未迁移或值无效时用 600。
     */
    public function recordTtl(): int
    {
        if (! array_key_exists('record_ttl', $this->attributes) && ! static::hasRecordTtlColumn()) {
            return 600;
        }

        return static::normalizeRecordTtl($this->attributes['record_ttl'] ?? null);
    }

    public static function hasRecordTtlColumn(): bool
    {
        return Schema::hasTable('redi_platform_dns')
            && Schema::hasColumn('redi_platform_dns', 'record_ttl');
    }

    /**
     * @param  mixed  $value
     */
    public static function normalizeRecordTtl($value): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1)) {
            return 600;
        }
        $ttl = (int) $value;
        if ($ttl < 1 || $ttl > 86400) {
            return 600;
        }

        return $ttl;
    }

    public function isDnsApiMode(): bool
    {
        return strtolower(trim((string) $this->dns_mode)) === 'api';
    }

    public function poolFqdn(): string
    {
        $zone = trim($this->edge_zone, '.');
        $host = trim($this->pool_host, '.');

        if ($zone === '' || $host === '') {
            return '';
        }

        $fqdn = HostNameValidator::toCanonicalAscii(strtolower($host.'.'.$zone));
        if ($fqdn === '' || ! HostNameValidator::isValidHostLabel($fqdn)) {
            return '';
        }

        return $fqdn;
    }

    public function setApiCredentialsArray(?array $data): void
    {
        $this->api_credentials = $data ? Crypt::encryptString(json_encode($data)) : null;
    }

    public function getApiCredentialsArray(): array
    {
        if (! $this->api_credentials) {
            return [];
        }

        try {
            $json = Crypt::decryptString($this->api_credentials);
            $data = json_decode($json, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('RediPlatformDns: api_credentials decrypt failed', [
                'id' => $this->id,
                'error' => OpsDisplaySanitizer::logSnippet($e->getMessage(), 300),
            ]);

            throw new \RuntimeException(
                __('redi-platform-dns.messages.api_credentials_decrypt'),
                0,
                $e
            );
        }
    }
}
