<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Tests\TestCase;

class RediNodeCanonicalPublicIpv6Test extends TestCase
{
    public function test_normalizes_equivalent_forms(): void
    {
        $one = RediNode::canonicalPublicIpv6('2001:db8::1');
        $this->assertNotSame('', $one);
        $this->assertSame($one, RediNode::canonicalPublicIpv6('2001:db8::1.'));
        $this->assertSame($one, RediNode::canonicalPublicIpv6('2001:0db8::1'));
        $this->assertSame(
            strtolower((string) inet_ntop(inet_pton('2001:db8::1'))),
            $one
        );

        $embedded = RediNode::canonicalPublicIpv6('2001:db8::192.0.2.1');
        $this->assertSame($embedded, RediNode::canonicalPublicIpv6('2001:db8::c000:201'));
    }

    public function test_invalid_returns_empty_string(): void
    {
        $this->assertSame('', RediNode::canonicalPublicIpv6('not-an-ip'));
        $this->assertSame('', RediNode::canonicalPublicIpv6('1.2.3.4'));
    }
}
