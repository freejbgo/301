<?php

namespace App\Models;

use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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
    ];

    protected $guarded = [
        'api_credentials',
    ];

    protected $casts = [
        'acme_staging' => 'boolean',
    ];

    public static function instance(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'edge_zone' => '',
                'pool_host' => 'pool',
                'dns_mode' => 'manual',
                'dns_driver' => 'manual',
            ]
        );
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
                '平台 DNS API 凭证无法解密（请确认 APP_KEY 未变更，或在后台重新保存 API 密钥）',
                0,
                $e
            );
        }
    }
}
