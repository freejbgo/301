<?php

namespace Tests\Unit;

use App\Services\EdgeDnsManualReference;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EdgeDnsManualReferenceTest extends TestCase
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

        Schema::create('redi_platform_dns', function (Blueprint $table) {
            $table->increments('id');
            $table->string('edge_zone')->nullable();
            $table->string('pool_host')->nullable();
            $table->string('dns_mode')->default('manual');
            $table->string('dns_driver')->default('manual');
            $table->timestamps();
        });

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('public_ip', 45)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('redi_tenant_edge', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('edge_fqdn')->nullable();
            $table->string('token')->nullable();
            $table->timestamps();
        });

        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_string_false_publish_flag_adds_no_aaaa_rows(): void
    {
        config(['redi.publish_pool_aaaa' => 'false']);
        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'public_ipv6' => 'fe80::1',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pool = EdgeDnsManualReference::tables()['pool'];

        $this->assertSame([['A', 'pool.example.com', '1.2.3.4']], $pool);
    }

    public function test_publish_true_lists_valid_aaaa_and_note_for_unreadable_enabled(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'public_ipv6' => '2001:db8::1',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_nodes')->insert([
            'public_ip' => '5.6.7.8',
            'public_ipv6' => 'fe80::1',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pool = EdgeDnsManualReference::tables()['pool'];

        $aRows = array_values(array_filter($pool, fn ($row) => $row[0] === 'A'));
        $aaaaRows = array_values(array_filter($pool, fn ($row) => $row[0] === 'AAAA'));
        $this->assertCount(2, $aRows);
        $this->assertContains(['AAAA', 'pool.example.com', '2001:db8::1'], $aaaaRows);
        $this->assertTrue(collect($aaaaRows)->contains(fn ($row) => str_contains($row[2], '无法解析')));
    }

    public function test_no_ipv4_placeholder_row_remains_when_publish_true(): void
    {
        config(['redi.publish_pool_aaaa' => true]);

        $pool = EdgeDnsManualReference::tables()['pool'];

        $this->assertSame(
            ['A', 'pool.example.com', '（请先在节点管理添加启用节点 IP）'],
            $pool[0]
        );
        $this->assertCount(1, $pool);
    }

    public function test_unreadable_on_disabled_node_does_not_add_note(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'public_ipv6' => '2001:db8::1',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_nodes')->insert([
            'public_ip' => '5.6.7.8',
            'public_ipv6' => 'fe80::1',
            'enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pool = EdgeDnsManualReference::tables()['pool'];

        $this->assertCount(2, $pool);
        $this->assertSame(['AAAA', 'pool.example.com', '2001:db8::1'], $pool[1]);
    }
}
