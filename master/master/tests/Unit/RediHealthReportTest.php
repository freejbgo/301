<?php

namespace Tests\Unit;

use App\Services\RediHealthReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediHealthReportTest extends TestCase
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
            $table->boolean('enabled')->default(true);
            $table->string('public_ip', 45)->nullable();
            $table->string('name')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name');
            $table->string('status')->default('active');
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_platform_dns', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('edge_zone')->nullable();
            $table->string('pool_host')->nullable();
            $table->string('dns_mode')->default('manual');
            $table->string('dns_driver')->default('manual');
            $table->string('dns_zone_id')->nullable();
            $table->text('api_credentials')->nullable();
            $table->string('acme_contact_email')->nullable();
            $table->boolean('acme_staging')->default(false);
            $table->timestamps();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_summary_includes_configured_queue_health_thresholds(): void
    {
        config([
            'redi.health_queue_pending_threshold' => 90,
            'redi.health_queue_stale_hours' => 3,
            'redi.health_validating_stuck_hours' => 4,
            'redi.renew_validating_stuck_hours' => 5,
        ]);

        $summary = RediHealthReport::summary();

        $this->assertSame(90, $summary['health_queue_pending_threshold']);
        $this->assertSame(3, $summary['health_queue_stale_hours']);
        $this->assertSame(4, $summary['health_validating_stuck_hours']);
        $this->assertSame(5, $summary['health_renew_validating_stuck_hours']);
    }

    public function test_collect_issues_heals_cert_domain_name_drift(): void
    {
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'canonical.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'stale.example',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertNotContains('cert_domain_name_drift', $codes);
        $this->assertSame(
            'canonical.example',
            \DB::table('redi_domain_certs')->where('domain_id', $domainId)->value('domain_name')
        );
    }

    public function test_collect_issues_skips_invalid_cert_host_for_expired(): void
    {
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => "evil.com\r\n",
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => "evil.com\r\n",
            'status' => 'active',
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertNotContains('cert_expired', $codes);
    }

    public function test_collect_issues_includes_queue_stale_when_database_queue(): void
    {
        config(['queue.default' => 'database']);
        $staleAt = now()->subHours(3)->timestamp;
        \DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $staleAt,
            'created_at' => $staleAt,
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('queue_stale', $codes);
    }

    public function test_queue_stale_respects_configurable_stale_hours(): void
    {
        config(['queue.default' => 'database', 'redi.health_queue_stale_hours' => 4]);
        $recentAt = now()->subHours(3)->timestamp;
        \DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $recentAt,
            'created_at' => $recentAt,
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertNotContains('queue_stale', $codes);

        \DB::table('jobs')->truncate();
        $staleAt = now()->subHours(5)->timestamp;
        \DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $staleAt,
            'created_at' => $staleAt,
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('queue_stale', $codes);
    }

    public function test_queue_stale_not_reported_for_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        $staleAt = now()->subHours(3)->timestamp;
        \DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $staleAt,
            'created_at' => $staleAt,
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertNotContains('queue_stale', $codes);
    }

    public function test_collect_issues_includes_failed_jobs_when_database_queue(): void
    {
        config(['queue.default' => 'database']);
        \DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('queue_failed_jobs', $codes);
    }

    public function test_collect_issues_includes_pending_deployments(): void
    {
        $nodeId = \DB::table('redi_nodes')->insertGetId([
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'pull.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cert = \App\Models\RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => 'pull.example',
            'status' => 'deploying',
        ]);
        $cert->setCertificatePem("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----");
        $cert->setPrivateKeyPem("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");
        $cert->save();
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $nodeId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issues = RediHealthReport::collectIssues(7);
        $codes = array_column($issues, 'code');

        $this->assertContains('cert_deploy_pending', $codes);
    }

    public function test_collect_issues_ignores_pending_when_cert_not_node_pullable(): void
    {
        $nodeId = \DB::table('redi_nodes')->insertGetId([
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'disabled.example',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'disabled.example',
            'status' => 'deploying',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodeId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertNotContains('cert_deploy_pending', $codes);
    }

    public function test_summary_deploy_counts_match_actionable_deployments_only(): void
    {
        $nodePending = \DB::table('redi_nodes')->insertGetId([
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nodeFailed = \DB::table('redi_nodes')->insertGetId([
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'disabled.example',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'disabled.example',
            'status' => 'deploying',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodePending,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodeFailed,
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = RediHealthReport::summary();
        $this->assertSame(0, $summary['pending_deploys']);
        $this->assertSame(0, $summary['failed_deploys']);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertNotContains('cert_deploy_pending', $codes);
        $this->assertNotContains('cert_deploy_failed', $codes);
    }

    public function test_collect_hints_warns_file_cache_with_database_queue(): void
    {
        config(['queue.default' => 'database']);
        config(['cache.default' => 'file']);

        $codes = array_column(RediHealthReport::collectHints(), 'code');
        $this->assertContains('queue_cache_file', $codes);
    }

    public function test_collect_hints_agent_version_gate_off_in_production(): void
    {
        config(['app.env' => 'production', 'redi.min_agent_version' => '']);

        $codes = array_column(RediHealthReport::collectHints(), 'code');
        $this->assertContains('agent_version_gate_off', $codes);
    }

    public function test_collect_issues_includes_stuck_validating_cert(): void
    {
        config(['redi.health_validating_stuck_hours' => 2]);

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'stuck.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'stuck.example',
            'status' => 'validating',
            'expires_at' => now()->addMonth(),
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('cert_validating_stuck', $codes);
    }

    public function test_cert_validating_stuck_reports_total_count_in_message(): void
    {
        config(['redi.health_validating_stuck_hours' => 1]);
        for ($i = 0; $i < 3; $i++) {
            $domainId = \DB::table('redi_admin_domain')->insertGetId([
                'original_url' => "stuck{$i}.example",
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \DB::table('redi_domain_certs')->insert([
                'domain_id' => $domainId,
                'domain_name' => "stuck{$i}.example",
                'status' => 'validating',
                'expires_at' => now()->addMonth(),
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]);
        }

        $issues = RediHealthReport::collectIssues(7);
        $row = collect($issues)->firstWhere('code', 'cert_validating_stuck');
        $this->assertNotNull($row);
        $this->assertStringContainsString('3 张证书', $row['message']);
    }

    public function test_collect_issues_warns_acme_staging_in_production(): void
    {
        config(['app.env' => 'production']);
        \DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'acme_staging' => true,
            'acme_contact_email' => 'ops@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('acme_staging_enabled', $codes);
    }

    public function test_production_config_issues_include_missing_acme_email(): void
    {
        config(['app.env' => 'production', 'app.debug' => false]);
        \DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'acme_staging' => false,
            'acme_contact_email' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('acme_email_missing', $codes);
    }

    public function test_app_debug_critical_in_production(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('app_debug_enabled', $codes);
    }

    public function test_production_config_issues_include_missing_edge_zone(): void
    {
        config(['app.env' => 'production', 'app.debug' => false]);
        \DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => '',
            'pool_host' => 'pool',
            'acme_staging' => false,
            'acme_contact_email' => 'ops@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('edge_zone_missing', $codes);
    }

    public function test_collect_issues_warns_when_no_enabled_nodes(): void
    {
        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('node_pool_empty', $codes);
    }

    public function test_collect_issues_warns_enabled_nodes_without_public_ip(): void
    {
        \DB::table('redi_nodes')->insert([
            'enabled' => true,
            'public_ip' => null,
            'name' => 'n1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('node_no_public_ip', $codes);
    }

    public function test_production_warns_when_dns_api_mode_with_manual_driver(): void
    {
        config(['app.env' => 'production', 'app.debug' => false]);
        \DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'manual',
            'acme_staging' => false,
            'acme_contact_email' => 'ops@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('dns_driver_invalid', $codes);
    }

    public function test_production_warns_when_dns_api_mode_without_credentials(): void
    {
        config(['app.env' => 'production', 'app.debug' => false]);
        \DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'dns_zone_id' => 'zone123',
            'acme_staging' => false,
            'acme_contact_email' => 'ops@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('dns_api_credentials_missing', $codes);
    }

    public function test_production_warns_cloudflare_without_zone_id(): void
    {
        config(['app.env' => 'production', 'app.debug' => false]);
        $platform = new \App\Models\RediPlatformDns([
            'id' => 1,
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'dns_zone_id' => '',
            'acme_staging' => false,
            'acme_contact_email' => 'ops@example.com',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token', 'api_secret' => '']);
        $platform->save();

        $codes = array_column(RediHealthReport::collectIssues(7), 'code');
        $this->assertContains('dns_zone_id_missing', $codes);
        $this->assertNotContains('dns_api_credentials_missing', $codes);
    }

    public function test_collect_hints_dns_api_tenants_unsynced(): void
    {
        Schema::create('redi_tenant_edge', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->string('token')->nullable();
            $table->string('edge_fqdn')->nullable();
            $table->timestamp('dns_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::table('redi_admin_domain', function (Blueprint $table) {
            $table->unsignedInteger('admin_id')->default(1);
        });

        $platform = new \App\Models\RediPlatformDns([
            'id' => 1,
            'edge_zone' => 'example.com',
            'pool_host' => 'pool',
            'dns_mode' => 'api',
            'dns_driver' => 'cloudflare',
            'dns_zone_id' => 'zone123',
            'acme_staging' => false,
            'acme_contact_email' => 'ops@example.com',
        ]);
        $platform->setApiCredentialsArray(['api_key' => 'token', 'api_secret' => '']);
        $platform->save();

        \DB::table('redi_tenant_edge')->insert([
            'admin_id' => 9,
            'token' => 'tok',
            'edge_fqdn' => 'tok.example.com',
            'dns_synced_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_admin_domain')->insert([
            'admin_id' => 9,
            'original_url' => 'cust.example.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_column(RediHealthReport::collectHints(), 'code');
        $this->assertContains('dns_api_tenants_unsynced', $codes);
    }
}
