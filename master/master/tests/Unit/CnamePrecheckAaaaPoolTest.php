<?php

namespace Tests\Unit;

use App\Services\CnamePrecheckService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CnamePrecheckAaaaPoolTest extends TestCase
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
            $table->increments('id');
            $table->string('public_ip', 45)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_returns_false_for_invalid_host(): void
    {
        $this->assertFalse(CnamePrecheckService::aaaaRecordPointsToPool('1.2.3.4'));
    }

    public function test_returns_false_when_ipv6_columns_missing(): void
    {
        Schema::drop('redi_nodes');
        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('public_ip', 45)->nullable();
            $table->boolean('enabled')->default(true);
        });

        $this->assertFalse(CnamePrecheckService::aaaaRecordPointsToPool('customer.example.com'));
    }

    public function test_returns_false_when_pool_has_no_enabled_ipv6(): void
    {
        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(CnamePrecheckService::aaaaRecordPointsToPool('customer.example.com'));
    }

    public function test_matches_ipv6_key_using_byte_comparison(): void
    {
        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'public_ipv6' => '2001:db8::1',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checker = new class extends CnamePrecheckService
        {
            /** @var string[] */
            public static array $aaaa = [];

            protected static function resolveAAAA(string $host): array
            {
                return self::$aaaa;
            }
        };

        $checker::$aaaa = ['2001:0db8::1'];
        $this->assertTrue($checker::aaaaRecordPointsToPool('customer.example.com'));

        $checker::$aaaa = ['2001:db8::2'];
        $this->assertFalse($checker::aaaaRecordPointsToPool('customer.example.com'));
    }
}
