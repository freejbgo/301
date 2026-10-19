<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediNodeParseExtraIpv6Test extends TestCase
{
    public function test_whitespace_split_not_colon(): void
    {
        $ips = RediNode::parseExtraIpv6("2001:db8::1\n2001:0db8::2");

        $this->assertCount(2, $ips);
        $this->assertSame(RediNode::canonicalPublicIpv6('2001:db8::1'), $ips[0]);
        $this->assertSame(RediNode::canonicalPublicIpv6('2001:db8::2'), $ips[1]);
    }

    public function test_dedupes_equivalent_addresses(): void
    {
        $ips = RediNode::parseExtraIpv6('2001:db8::1 2001:0db8::1');

        $this->assertSame([RediNode::canonicalPublicIpv6('2001:db8::1')], $ips);
    }

    public function test_invalid_includes_ipv6_label_and_original_token(): void
    {
        try {
            RediNode::parseExtraIpv6('fe80::1');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $message = $e->errors()['extra_ipv6'][0] ?? '';
            $this->assertStringContainsString('IPv6', $message);
            $this->assertStringContainsString('fe80::1', $message);
        }
    }
}
