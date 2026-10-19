<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediNodeAssertIpv6AvailableTest extends TestCase
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

    public function test_disabled_node_extra_ipv6_blocks_other_nodes(): void
    {
        RediNode::forceCreate([
            'name' => 'disabled',
            'public_ip' => '203.0.113.1',
            'public_ipv6' => '2001:db8::1',
            'extra_ipv6' => json_encode(['2001:db8::2']),
            'enabled' => false,
        ]);

        $this->expectException(ValidationException::class);
        RediNode::assertIpv6Available(['2001:db8::2']);
    }

    public function test_except_id_skips_self(): void
    {
        $node = RediNode::forceCreate([
            'name' => 'self',
            'public_ip' => '203.0.113.2',
            'public_ipv6' => '2001:db8::3',
            'enabled' => true,
        ]);

        RediNode::assertIpv6Available(['2001:db8::3'], $node->id);
        $this->addToAssertionCount(1);
    }

    public function test_equivalent_writing_collides(): void
    {
        RediNode::forceCreate([
            'name' => 'other',
            'public_ip' => '203.0.113.4',
            'public_ipv6' => '2001:0db8::5',
            'enabled' => true,
        ]);

        try {
            RediNode::assertIpv6Available(['2001:db8::5']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('public_ipv6', $e->errors());
        }
    }
}
