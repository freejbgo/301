<?php

namespace Tests\Unit;

use App\Services\CnamePrecheckService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CnamePrecheckPointsToEdgeAaaaTest extends TestCase
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
            $table->timestamps();
        });

        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_points_to_edge_checks_aaaa_after_a_miss_when_allowed(): void
    {
        config([
            'redi.dns_precheck_allow_a_to_pool' => true,
            'redi.dns_precheck_allow_aaaa_to_pool' => true,
        ]);

        $checker = new class extends CnamePrecheckService
        {
            public static bool $aHit = false;

            public static bool $aaaaHit = true;

            public static function aRecordPointsToPool(string $customerDomain): bool
            {
                return self::$aHit;
            }

            public static function aaaaRecordPointsToPool(string $customerDomain): bool
            {
                return self::$aaaaHit;
            }
        };

        $this->assertTrue($checker::pointsToEdge('customer.example.com', 'edge.example.com'));
    }

    public function test_string_false_aaaa_switch_blocks_aaaa_only_hit(): void
    {
        config([
            'redi.dns_precheck_allow_a_to_pool' => false,
            'redi.dns_precheck_allow_aaaa_to_pool' => 'false',
        ]);

        $checker = new class extends CnamePrecheckService
        {
            public static function aaaaRecordPointsToPool(string $customerDomain): bool
            {
                return true;
            }
        };

        $this->assertFalse($checker::pointsToEdge('customer.example.com', 'edge.example.com'));
    }

    public function test_cached_key_varies_with_aaaa_switch(): void
    {
        $captured = [];
        Cache::shouldReceive('remember')
            ->twice()
            ->andReturnUsing(function ($key, $ttl, $callback) use (&$captured) {
                $captured[] = $key;

                return $callback();
            });

        config([
            'redi.dns_precheck_allow_a_to_pool' => false,
            'redi.dns_precheck_allow_aaaa_to_pool' => false,
        ]);
        CnamePrecheckService::pointsToEdgeCached('customer.example.com', 'edge.example.com');

        config(['redi.dns_precheck_allow_aaaa_to_pool' => true]);
        CnamePrecheckService::pointsToEdgeCached('customer.example.com', 'edge.example.com');

        $this->assertCount(2, $captured);
        $this->assertNotSame($captured[0], $captured[1]);
    }
}
