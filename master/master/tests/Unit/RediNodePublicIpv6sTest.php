<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediNodePublicIpv6sTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
    }

    public function test_returns_empty_when_ipv6_columns_missing(): void
    {
        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('public_ip', 45)->nullable();
        });

        $node = new RediNode(['public_ip' => '1.2.3.4']);

        $this->assertSame([], $node->publicIpv6s());
    }

    public function test_lists_primary_then_valid_extras_and_skips_invalid(): void
    {
        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
        });

        $node = new RediNode();
        $node->setRawAttributes([
            'public_ipv6' => '2001:db8::1',
            'extra_ipv6' => 'fe80::1',
        ], true);

        $this->assertSame([
            RediNode::canonicalPublicIpv6('2001:db8::1'),
        ], $node->publicIpv6s());

        $node->setRawAttributes([
            'public_ipv6' => '2001:db8::1',
            'extra_ipv6' => '["2001:0db8::2","fe80::1"]',
        ], true);

        $this->assertSame([
            RediNode::canonicalPublicIpv6('2001:db8::1'),
            RediNode::canonicalPublicIpv6('2001:db8::2'),
        ], $node->publicIpv6s());
    }
}
