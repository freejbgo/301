<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Tests\TestCase;

class RediNodeIpv6ToBytesTest extends TestCase
{
    public function test_accepts_ipv6_with_trailing_ipv4_notation(): void
    {
        $expected = inet_pton('2001:db8::192.0.2.1');
        $this->assertSame(16, strlen($expected));
        $this->assertSame($expected, RediNode::ipv6ToBytes('2001:db8::192.0.2.1'));
        $this->assertSame($expected, RediNode::ipv6ToBytes('  2001:db8::192.0.2.1  '));
    }

    public function test_rejects_brackets_zone_id_slash_and_internal_whitespace(): void
    {
        $this->assertSame('', RediNode::ipv6ToBytes('[2001:db8::1]'));
        $this->assertSame('', RediNode::ipv6ToBytes('2001:db8::1%eth0'));
        $this->assertSame('', RediNode::ipv6ToBytes('2001:db8::1/64'));
        $this->assertSame('', RediNode::ipv6ToBytes('2001:db8::1 2001:db8::2'));
    }

    public function test_rejects_ipv4_and_garbage_without_php_warnings(): void
    {
        $this->assertSame('', RediNode::ipv6ToBytes('1.2.3.4'));
        $this->assertSame('', RediNode::ipv6ToBytes('not-an-ip'));
        $this->assertSame('', RediNode::ipv6ToBytes(''));
    }
}
