<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use PHPUnit\Framework\TestCase;

class RediPlatformDnsTest extends TestCase
{
    public function test_pool_fqdn_returns_valid_label(): void
    {
        $platform = new RediPlatformDns([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
        ]);

        $this->assertSame('pool.example.com', $platform->poolFqdn());
    }

    public function test_pool_fqdn_empty_when_labels_invalid(): void
    {
        $platform = new RediPlatformDns([
            'edge_zone' => 'example.com',
            'pool_host' => '..',
        ]);

        $this->assertSame('', $platform->poolFqdn());
    }
}
