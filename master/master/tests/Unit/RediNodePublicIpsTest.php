<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Tests\TestCase;

class RediNodePublicIpsTest extends TestCase
{
    public function test_public_ips_skips_invalid_ipv4(): void
    {
        $node = new RediNode([
            'public_ip' => 'not-an-ip',
            'extra_ips' => ['256.1.1.1', '5.6.7.8'],
        ]);

        $this->assertSame(['5.6.7.8'], $node->publicIps());
    }

    public function test_public_ips_deduplicates_primary_and_extra(): void
    {
        $node = new RediNode([
            'public_ip' => '1.2.3.4',
            'extra_ips' => ['1.2.3.4', '5.6.7.8'],
        ]);

        $this->assertSame(['1.2.3.4', '5.6.7.8'], $node->publicIps());
    }
}
