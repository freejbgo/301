<?php

namespace Tests\Unit;

use App\Services\Dns\CloudflareDnsProvider;
use App\Models\RediPlatformDns;
use RuntimeException;
use Tests\TestCase;

class EnsuresValidDnsNamesTest extends TestCase
{
    public function test_cloudflare_upsert_rejects_invalid_fqdn(): void
    {
        $platform = new RediPlatformDns([
            'dns_zone_id' => 'zone123',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DNS FQDN 无效');

        $provider->upsertARecords('1.2.3.4', ['1.2.3.4']);
    }

    public function test_cloudflare_upsert_cname_rejects_invalid_target(): void
    {
        $platform = new RediPlatformDns([
            'dns_zone_id' => 'zone123',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CNAME 目标 无效');

        $provider->upsertCname('tok', 'example.com', '1.2.3.4');
    }
}
