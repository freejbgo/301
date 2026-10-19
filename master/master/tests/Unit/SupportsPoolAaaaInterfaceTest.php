<?php

namespace Tests\Unit;

use App\Services\Dns\AliyunCnDnsProvider;
use App\Services\Dns\AliyunDnsProvider;
use App\Services\Dns\AliyunIntlDnsProvider;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\DnsComDnsProvider;
use App\Services\Dns\DnspodCnDnsProvider;
use App\Services\Dns\DnspodDnsProvider;
use App\Services\Dns\DnspodIntlDnsProvider;
use App\Services\Dns\ManualDnsProvider;
use App\Services\Dns\SupportsPoolAAAA;
use Tests\TestCase;

class SupportsPoolAaaaInterfaceTest extends TestCase
{
    public function test_implemented_supports_pool_aaaa_on_parent_providers(): void
    {
        $implemented = [
            CloudflareDnsProvider::class,
            AliyunDnsProvider::class,
            AliyunCnDnsProvider::class,
            AliyunIntlDnsProvider::class,
            DnspodDnsProvider::class,
            DnspodCnDnsProvider::class,
            DnspodIntlDnsProvider::class,
        ];
        foreach ($implemented as $class) {
            $this->assertContains(SupportsPoolAAAA::class, class_implements($class) ?: [], $class);
        }

        $others = [
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
