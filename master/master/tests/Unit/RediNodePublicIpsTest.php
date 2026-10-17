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

    public function test_parse_extra_ips_strips_control_chars_from_tokens(): void
    {
        $ips = RediNode::parseExtraIps("1.2.3.4\t5.6.7.8");

        $this->assertSame(['1.2.3.4', '5.6.7.8'], $ips);
    }

    public function test_filter_valid_ipv4_list_dedupes_and_drops_invalid(): void
    {
        $this->assertSame(
            ['1.2.3.4', '5.6.7.8'],
            RediNode::filterValidIpv4List(['1.2.3.4', 'bad', '5.6.7.8', '1.2.3.4'])
        );
    }
}
