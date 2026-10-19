<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Tests\TestCase;

class RediNodeFilterValidIpv6ListTest extends TestCase
{
    public function test_dedupes_by_address_and_returns_canonical_strings(): void
    {
        $list = RediNode::filterValidIpv6List([
            '2001:db8::1',
            '2001:0db8::1',
            'fe80::1',
            'bad',
            '  2001:db8::2  ',
            '2001:db8::2',
        ]);

        $this->assertCount(2, $list);
        $this->assertSame(RediNode::canonicalPublicIpv6('2001:db8::1'), $list[0]);
        $this->assertSame(RediNode::canonicalPublicIpv6('2001:db8::2'), $list[1]);
    }
}
