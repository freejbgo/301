<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnsProviderFactory;
use App\Services\Dns\ManualDnsProvider;
use InvalidArgumentException;
use Tests\TestCase;

class DnsProviderFactoryTest extends TestCase
{
    public function test_manual_mode_returns_manual_provider(): void
    {
        $platform = new RediPlatformDns([
            'dns_mode' => 'manual',
            'dns_driver' => 'cloudflare',
        ]);

        $this->assertInstanceOf(ManualDnsProvider::class, DnsProviderFactory::make($platform));
    }

    public function test_api_mode_unknown_driver_throws(): void
    {
        $platform = new RediPlatformDns([
            'dns_mode' => 'api',
            'dns_driver' => 'not_a_real_driver',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('未知 DNS API 驱动');

        DnsProviderFactory::make($platform);
    }

    public function test_supported_api_driver_list_matches_factory(): void
    {
        $this->assertTrue(DnsProviderFactory::isSupportedApiDriver('cloudflare'));
        $this->assertFalse(DnsProviderFactory::isSupportedApiDriver('typo_driver'));
        $this->assertContains('dns_com', DnsProviderFactory::supportedApiDrivers());
    }

    public function test_supported_api_driver_normalizes_case_and_whitespace(): void
    {
        $this->assertTrue(DnsProviderFactory::isSupportedApiDriver('  Cloudflare  '));
        $this->assertTrue(DnsProviderFactory::isSupportedApiDriver('DNS_COM'));
    }

    public function test_api_dns_mode_is_case_insensitive(): void
    {
        $platform = new RediPlatformDns([
            'dns_mode' => 'API',
            'dns_driver' => 'not_a_real_driver',
        ]);

        $this->expectException(InvalidArgumentException::class);

        DnsProviderFactory::make($platform);
    }
}
