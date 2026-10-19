<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediNodeEnabledPublicIpv6sTest extends TestCase
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

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_skips_disabled_nodes(): void
    {
        RediNode::forceCreate([
            'name' => 'off',
            'public_ip' => '203.0.113.1',
            'public_ipv6' => '2001:db8::1',
            'enabled' => false,
        ]);
        RediNode::forceCreate([
            'name' => 'on',
            'public_ip' => '203.0.113.2',
            'public_ipv6' => '2001:db8::2',
            'enabled' => true,
        ]);

        $this->assertSame([
            RediNode::canonicalPublicIpv6('2001:db8::2'),
        ], RediNode::enabledPublicIpv6s());
    }

    public function test_dedupes_by_ipv6_bytes_across_nodes(): void
    {
        RediNode::forceCreate([
            'name' => 'a',
            'public_ip' => '203.0.113.3',
            'public_ipv6' => '2001:0db8::5',
            'enabled' => true,
        ]);
        RediNode::forceCreate([
            'name' => 'b',
            'public_ip' => '203.0.113.4',
            'public_ipv6' => '2001:db8::5',
            'enabled' => true,
        ]);

        $this->assertSame([
            RediNode::canonicalPublicIpv6('2001:db8::5'),
        ], RediNode::enabledPublicIpv6s());
    }

    public function test_collects_extras_from_enabled_nodes_in_id_order(): void
    {
        RediNode::forceCreate([
            'name' => 'n1',
            'public_ip' => '203.0.113.5',
            'public_ipv6' => '2001:db8::1',
            'extra_ipv6' => ['2001:db8::3'],
            'enabled' => true,
        ]);
        RediNode::forceCreate([
            'name' => 'n2',
            'public_ip' => '203.0.113.6',
            'public_ipv6' => '2001:db8::2',
            'enabled' => true,
        ]);

        $this->assertSame([
            RediNode::canonicalPublicIpv6('2001:db8::1'),
            RediNode::canonicalPublicIpv6('2001:db8::3'),
            RediNode::canonicalPublicIpv6('2001:db8::2'),
        ], RediNode::enabledPublicIpv6s());
    }
}
