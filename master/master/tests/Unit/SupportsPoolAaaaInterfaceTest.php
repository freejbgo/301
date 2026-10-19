<?php

namespace Tests\Unit;

use App\Services\Dns\AliyunCnDnsProvider;
use App\Services\Dns\AliyunIntlDnsProvider;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\DnsComDnsProvider;
use App\Services\Dns\DnspodCnDnsProvider;
use App\Services\Dns\DnspodIntlDnsProvider;
use App\Services\Dns\ManualDnsProvider;
use App\Services\Dns\SupportsPoolAAAA;
use Tests\TestCase;

class SupportsPoolAaaaInterfaceTest extends TestCase
{
    public function test_no_dns_provider_implements_supports_pool_aaaa_yet(): void
    {
        $providers = [
            CloudflareDnsProvider::class,
            AliyunCnDnsProvider::class,
            AliyunIntlDnsProvider::class,
            DnspodCnDnsProvider::class,
            DnspodIntlDnsProvider::class,
            DnsComDnsProvider::class,
            ManualDnsProvider::class,
        ];

        foreach ($providers as $class) {
            $implements = class_implements($class) ?: [];
            $this->assertArrayNotHasKey(
                SupportsPoolAAAA::class,
                $implements,
                $class.' must not implement SupportsPoolAAAA in V6-13'
            );
        }
    }
}
