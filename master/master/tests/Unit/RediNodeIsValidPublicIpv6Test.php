<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Tests\TestCase;

class RediNodeIsValidPublicIpv6Test extends TestCase
{
    public function test_accepts_global_unicast(): void
    {
        $this->assertTrue(RediNode::isValidPublicIpv6('2001:db8::1'));
    }

    public function test_rejects_non_global_unicast_and_mapped(): void
    {
        foreach ([
            '::ffff:192.0.2.1',
            '::1',
            'fe80::1',
            'fe90::1',
            'fc00::1',
            'fd00::1',
            'ff02::1',
            '1.2.3.4',
            'not-an-ip',
        ] as $ip) {
            $this->assertFalse(RediNode::isValidPublicIpv6($ip), $ip);
        }
    }
}
