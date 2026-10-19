<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnsProviderInterface;
use App\Services\Dns\SupportsPoolAAAA;
use App\Services\EdgeDnsSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
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

    public function test_string_false_publish_flag_skips_aaaa_even_with_bad_ipv6(): void
    {
        config(['redi.publish_pool_aaaa' => 'false']);
        $this->seedPlatformAndNode();
        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
        });
        \DB::table('redi_nodes')->update(['public_ipv6' => 'fe80::1']);

        $provider = $this->trackingProvider(false);
        app(EdgeDnsSyncService::class)->syncAll($provider);

        $this->assertSame(1, $provider->aCalls);
        $this->assertSame(0, $provider->aaaaCalls);
        $log = \DB::table('redi_dns_sync_logs')->orderByDesc('id')->value('message');
        $this->assertStringNotContainsString('AAAA', (string) $log);
    }

    public function test_publish_true_aborts_before_a_when_enabled_node_has_bad_ipv6(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();
        $this->addIpv6Columns();
        \DB::table('redi_nodes')->update(['public_ipv6' => 'fe80::1']);

        $provider = $this->trackingProvider(true);

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('无法发布', $e->getMessage());
        }

        $this->assertSame(0, $provider->aCalls);
        $this->assertSame(0, $provider->aaaaCalls);
    }

    public function test_publish_true_allows_a_when_only_disabled_node_has_bad_ipv6(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();
        $this->addIpv6Columns();
        \DB::table('redi_nodes')->update([
            'enabled' => 0,
            'public_ipv6' => 'fe80::1',
        ]);

        $provider = $this->trackingProvider(true);
        app(EdgeDnsSyncService::class)->syncAll($provider);

        $this->assertSame(1, $provider->aCalls);
        $this->assertSame(1, $provider->aaaaCalls);
    }

    public function test_publish_true_aborts_before_a_when_ipv6_columns_missing(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();

        $provider = $this->trackingProvider(true);

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('尚未迁移', $e->getMessage());
        }

        $this->assertSame(0, $provider->aCalls);
    }

    public function test_publish_true_without_supports_pool_aaaa_logs_warning_not_aaaa_in_success(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();
        $this->addIpv6Columns();

        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::on(fn ($message) => str_contains((string) $message, 'SupportsPoolAAAA')), \Mockery::type('array'));
        $provider = $this->trackingProvider(false);
        app(EdgeDnsSyncService::class)->syncAll($provider);

        $this->assertSame(1, $provider->aCalls);
        $this->assertSame(0, $provider->aaaaCalls);
        $log = \DB::table('redi_dns_sync_logs')->orderByDesc('id')->value('message');
        $this->assertStringNotContainsString('AAAA', (string) $log);
    }

    public function test_publish_true_empty_ipv6_still_invokes_aaaa_and_logs_x0(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();
        $this->addIpv6Columns();

        $provider = $this->trackingProvider(true);
        app(EdgeDnsSyncService::class)->syncAll($provider);

        $this->assertSame(1, $provider->aaaaCalls);
        $this->assertSame([], $provider->lastAaaaIpv6);
        $log = \DB::table('redi_dns_sync_logs')->orderByDesc('id')->value('message');
        $this->assertStringContainsString('pool A', (string) $log);
        $this->assertStringContainsString('AAAA x0', (string) $log);
    }

    public function test_aaaa_failure_does_not_sync_cname_or_edges(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();
        $this->addIpv6Columns();

        $provider = $this->trackingProvider(true, true);

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AAAA', $e->getMessage());
            $this->assertStringContainsString('Pool A 可能', $e->getMessage());
            $this->assertStringNotContainsString('Pool A 记录同步失败', $e->getMessage());
        }

        $this->assertSame(0, $provider->cnameCalls);
        $this->assertNull(\DB::table('redi_tenant_edge')->where('id', 1)->value('dns_synced_at'));
    }

    public function test_success_log_aaaa_count_from_enabled_public_ipv6s(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->seedPlatformAndNode();
        $this->addIpv6Columns();
        \DB::table('redi_nodes')->insert([
            'public_ip' => '5.6.7.8',
            'public_ipv6' => '2001:db8::2',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_nodes')->where('id', 1)->update(['public_ipv6' => '2001:db8::1']);

        $provider = $this->trackingProvider(true);
        app(EdgeDnsSyncService::class)->syncAll($provider);

        $log = \DB::table('redi_dns_sync_logs')->orderByDesc('id')->value('message');
        $this->assertStringContainsString('AAAA x2', (string) $log);
    }

    public function test_outside_zone_abort_mentions_aaaa_after_it_ran(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
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
        $this->addIpv6Columns();
        \DB::table('redi_tenant_edge')->insert([
            ['edge_fqdn' => 'ok.edge.example.com', 'created_at' => now(), 'updated_at' => now()],
            ['edge_fqdn' => 'bad.other.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $provider = $this->trackingProvider(true);

        try {
            app(EdgeDnsSyncService::class)->syncAll($provider);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Pool A', $e->getMessage());
            $this->assertStringContainsString('AAAA', $e->getMessage());
        }
    }

    private function seedPlatformAndNode(): void
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
            'edge_fqdn' => 'tok.edge.example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addIpv6Columns(): void
    {
        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
        });
    }

    /**
     * @return object{aCalls: int, aaaaCalls: int, cnameCalls: int, lastAaaaIpv6: ?array}
     */
    private function trackingProvider(bool $supportsAaaa, bool $aaaaFails = false): object
    {
        if ($supportsAaaa) {
            return new class($aaaaFails) implements DnsProviderInterface, SupportsPoolAAAA
            {
                public int $aCalls = 0;

                public int $aaaaCalls = 0;

                public int $cnameCalls = 0;

                /** @var string[]|null */
                public ?array $lastAaaaIpv6 = null;

                public function __construct(private bool $aaaaFails)
                {
                }

                public function testConnection(): void
                {
                }

                public function upsertARecords(string $fqdn, array $ipv4): void
                {
                    $this->aCalls++;
                }

                public function upsertAAAARecords(string $fqdn, array $ipv6): void
                {
                    $this->aaaaCalls++;
                    $this->lastAaaaIpv6 = $ipv6;
                    if ($this->aaaaFails) {
                        throw new RuntimeException('aaaa boom');
                    }
                }

                public function upsertCname(string $name, string $zone, string $targetFqdn): void
                {
                    $this->cnameCalls++;
                }
            };
        }

        return new class implements DnsProviderInterface
        {
            public int $aCalls = 0;

            public int $aaaaCalls = 0;

            public int $cnameCalls = 0;

            public ?array $lastAaaaIpv6 = null;

            public function testConnection(): void
            {
            }

            public function upsertARecords(string $fqdn, array $ipv4): void
            {
                $this->aCalls++;
            }

            public function upsertCname(string $name, string $zone, string $targetFqdn): void
            {
                $this->cnameCalls++;
            }
        };
    }
}
