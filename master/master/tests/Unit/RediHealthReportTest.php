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
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_platform_dns', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('edge_zone')->nullable();
            $table->string('pool_host')->nullable();
            $table->string('dns_mode')->default('manual');
            $table->string('dns_driver')->default('manual');
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
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => 1,
            'node_id' => $nodeId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issues = RediHealthReport::collectIssues(7);
        $codes = array_column($issues, 'code');

        $this->assertContains('cert_deploy_pending', $codes);
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
}
