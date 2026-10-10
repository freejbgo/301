<?php

namespace App\Models;

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
        'api_credentials',
        'dns_zone_id',
        'acme_contact_email',
        'acme_staging',
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

    public function poolFqdn(): string
    {
        $zone = trim($this->edge_zone, '.');
        $host = trim($this->pool_host, '.');

        if ($zone === '' || $host === '') {
            return '';
        }

        return strtolower($host.'.'.$zone);
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

            return json_decode($json, true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
