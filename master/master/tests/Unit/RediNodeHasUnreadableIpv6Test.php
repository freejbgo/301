<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediNodeHasUnreadableIpv6Test extends TestCase
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

    public function test_false_when_ipv6_columns_missing(): void
    {
        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('public_ip', 45)->nullable();
        });

        $node = new RediNode(['public_ip' => '1.2.3.4']);

        $this->assertFalse($node->hasUnreadableIpv6());
    }

    public function test_primary_invalid_is_true_valid_and_empty_are_false(): void
    {
        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
        });

        $node = new RediNode();
        $node->setRawAttributes(['public_ipv6' => 'fe80::1', 'extra_ipv6' => null], true);
        $this->assertTrue($node->hasUnreadableIpv6());

        $node->setRawAttributes(['public_ipv6' => '2001:db8::1', 'extra_ipv6' => null], true);
        $this->assertFalse($node->hasUnreadableIpv6());

        $node->setRawAttributes(['public_ipv6' => '', 'extra_ipv6' => null], true);
        $this->assertFalse($node->hasUnreadableIpv6());

        $node->setRawAttributes(['public_ipv6' => null, 'extra_ipv6' => null], true);
        $this->assertFalse($node->hasUnreadableIpv6());
    }

    public function test_non_json_extra_string_is_checked_as_whole(): void
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

        $this->assertTrue($node->hasUnreadableIpv6());
    }
}
