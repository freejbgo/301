<?php

namespace Tests\Unit;

use App\Services\Dns\AliyunCnDnsProvider;
use App\Services\Dns\AliyunDnsProvider;
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
    public function test_cloudflare_and_aliyun_parent_implement_supports_pool_aaaa(): void
    {
        $this->assertContains(SupportsPoolAAAA::class, class_implements(CloudflareDnsProvider::class) ?: []);
        $this->assertContains(SupportsPoolAAAA::class, class_implements(AliyunDnsProvider::class) ?: []);
        $this->assertContains(SupportsPoolAAAA::class, class_implements(AliyunCnDnsProvider::class) ?: []);
        $this->assertContains(SupportsPoolAAAA::class, class_implements(AliyunIntlDnsProvider::class) ?: []);

        $others = [
            DnspodCnDnsProvider::class,
            DnspodIntlDnsProvider::class,
            DnsComDnsProvider::class,
            ManualDnsProvider::class,
        ];

        foreach ($others as $class) {
            $implements = class_implements($class) ?: [];
            $this->assertArrayNotHasKey(
                SupportsPoolAAAA::class,
                $implements,
                $class.' must not implement SupportsPoolAAAA yet'
            );
        }
    }
}
