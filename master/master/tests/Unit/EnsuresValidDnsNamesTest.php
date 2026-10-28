<?php

namespace Tests\Unit;

use App\Services\Dns\CloudflareDnsProvider;
use App\Models\RediPlatformDns;
use App\Support\RediErrorMessage;
use RuntimeException;
use Tests\TestCase;

class EnsuresValidDnsNamesTest extends TestCase
{
    public function test_cloudflare_upsert_rejects_control_chars_in_fqdn(): void
    {
        $platform = new RediPlatformDns([
            'dns_zone_id' => 'zone123',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RediErrorMessage::get('dns.label_invalid', ['label' => 'DNS FQDN']));

        $provider->upsertARecords("pool\r\n.example.com", ['1.2.3.4']);
    }

    public function test_cloudflare_upsert_rejects_invalid_fqdn(): void
    {
        $platform = new RediPlatformDns([
            'dns_zone_id' => 'zone123',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RediErrorMessage::get('dns.label_invalid', ['label' => 'DNS FQDN']));

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
        $this->expectExceptionMessage(RediErrorMessage::get('dns.label_invalid', ['label' => 'CNAME target']));

        $provider->upsertCname('tok', 'example.com', '1.2.3.4');
    }

    public function test_cloudflare_upsert_cname_rejects_control_chars_in_record_name(): void
    {
        $platform = new RediPlatformDns([
            'dns_zone_id' => 'zone123',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RediErrorMessage::get('dns.host_invalid'));

        $provider->upsertCname("tok\r\n", 'example.com', 'pool.example.com');
    }
}
