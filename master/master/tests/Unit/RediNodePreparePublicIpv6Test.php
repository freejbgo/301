<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediNodePreparePublicIpv6Test extends TestCase
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
            $table->text('note')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_blank_becomes_null(): void
    {
        $this->assertNull(RediNode::preparePublicIpv6ForStorage(''));
        $this->assertNull(RediNode::preparePublicIpv6ForStorage('   '));
    }

    public function test_valid_stores_canonical(): void
    {
        $this->assertSame(
            RediNode::canonicalPublicIpv6('2001:0db8::1'),
            RediNode::preparePublicIpv6ForStorage('2001:db8::1')
        );
    }

    public function test_invalid_rejects_fe80(): void
    {
        $this->expectException(ValidationException::class);
        RediNode::preparePublicIpv6ForStorage('fe80::1');
    }

    public function test_saving_keeps_unreadable_ipv6_when_only_note_changes(): void
    {
        $node = RediNode::forceCreate([
            'name' => 'n1',
            'public_ip' => '203.0.113.1',
            'public_ipv6' => 'fe80::1',
        ]);
        $node->note = 'ops';
        $node->save();

        $this->assertSame('fe80::1', $node->fresh()->public_ipv6);
    }
}
