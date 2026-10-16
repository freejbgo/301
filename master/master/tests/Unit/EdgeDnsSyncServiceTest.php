<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnsProviderInterface;
use App\Services\EdgeDnsSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class EdgeDnsSyncServiceTest extends TestCase
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
            $table->string('dns_mode')->default('api');
            $table->string('dns_driver')->default('manual');
            $table->string('dns_zone_id')->nullable();
            $table->text('api_credentials')->nullable();
            $table->string('acme_contact_email')->nullable();
            $table->boolean('acme_staging')->default(false);
            $table->timestamps();
        });

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('public_ip', 45)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('redi_tenant_edge', function (Blueprint $table) {
            $table->increments('id');
            $table->string('edge_fqdn')->nullable();
            $table->timestamp('dns_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_dns_sync_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('action');
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function test_does_not_mark_edges_synced_when_cname_upsert_fails(): void
    {
        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_tenant_edge')->insert([
            ['edge_fqdn' => 'a.edge.example.com', 'created_at' => now(), 'updated_at' => now()],
            ['edge_fqdn' => 'b.edge.example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $provider = new class implements DnsProviderInterface
        {
            public int $cnameCalls = 0;

            public function testConnection(): void
            {
            }

            public function upsertARecords(string $fqdn, array $ipv4): void
            {
            }

            public function upsertCname(string $name, string $zone, string $targetFqdn): void
            {
                $this->cnameCalls++;
                if ($this->cnameCalls >= 2) {
                    throw new RuntimeException('simulated API failure');
                }
            }
        };

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated', $e->getMessage());
        }

        $this->assertNull(\DB::table('redi_tenant_edge')->where('id', 1)->value('dns_synced_at'));
        $this->assertNull(\DB::table('redi_tenant_edge')->where('id', 2)->value('dns_synced_at'));
    }

    public function test_aborts_before_pool_when_all_tenant_edges_outside_zone(): void
    {
        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_tenant_edge')->insert([
            'edge_fqdn' => 'token.wrong-zone.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provider = new class implements DnsProviderInterface
        {
            public int $poolCalls = 0;

            public function testConnection(): void
            {
            }

            public function upsertARecords(string $fqdn, array $ipv4): void
            {
                $this->poolCalls++;
            }

            public function upsertCname(string $name, string $zone, string $targetFqdn): void
            {
            }
        };

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('均不在当前平台主域名', $e->getMessage());
        }

        $this->assertSame(0, $provider->poolCalls);
    }

    public function test_aborts_when_some_tenant_edges_outside_zone(): void
    {
        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_tenant_edge')->insert([
            ['edge_fqdn' => 'ok.edge.example.com', 'created_at' => now(), 'updated_at' => now()],
            ['edge_fqdn' => 'bad.other.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $provider = new class implements DnsProviderInterface
        {
            public function testConnection(): void
            {
            }

            public function upsertARecords(string $fqdn, array $ipv4): void
            {
            }

            public function upsertCname(string $name, string $zone, string $targetFqdn): void
            {
            }
        };

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('不在平台域名', $e->getMessage());
        }

        $this->assertNull(\DB::table('redi_tenant_edge')->where('id', 1)->value('dns_synced_at'));
    }

    public function test_dns_mode_api_is_case_insensitive(): void
    {
        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'API',
            'dns_driver' => 'cloudflare',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_nodes')->insert([
            'public_ip' => '1.2.3.4',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_tenant_edge')->insert([
            'edge_fqdn' => 'tok.edge.example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provider = new class implements DnsProviderInterface
        {
            public function testConnection(): void
            {
            }

            public function upsertARecords(string $fqdn, array $ipv4): void
            {
            }

            public function upsertCname(string $name, string $zone, string $targetFqdn): void
            {
            }
        };

        app(EdgeDnsSyncService::class)->syncAll($provider);

        $this->assertNotNull(\DB::table('redi_tenant_edge')->where('id', 1)->value('dns_synced_at'));
    }

    public function test_sync_rejects_invalid_pool_fqdn(): void
    {
        \DB::table('redi_platform_dns')->insert([
            'edge_zone' => 'example.com',
            'pool_host' => '..',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provider = new class implements DnsProviderInterface
        {
            public function testConnection(): void
            {
            }

            public function upsertARecords(string $fqdn, array $ipv4): void
            {
            }

            public function upsertCname(string $name, string $zone, string $targetFqdn): void
            {
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pool FQDN 无效');

        app(EdgeDnsSyncService::class)->syncAll($provider);
    }
}
