<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediHealthCommandTest extends TestCase
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
            $table->string('name')->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->boolean('enabled')->default(true);
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
            $table->string('original_url');
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
    }

    public function test_health_ok_when_no_issues(): void
    {
        \DB::table('redi_nodes')->insert([
            'name' => 'ok',
            'public_ip' => '1.2.3.4',
            'enabled' => true,
            'last_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:health')->assertExitCode(0);
    }

    public function test_health_fails_on_stale_node(): void
    {
        \DB::table('redi_nodes')->insert([
            'name' => 'stale',
            'public_ip' => '1.2.3.4',
            'enabled' => true,
            'last_heartbeat_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:health')->assertExitCode(1);
    }

    public function test_health_stale_node_message_strips_control_chars(): void
    {
        \DB::table('redi_nodes')->insert([
            'name' => "evil\r\nnode",
            'public_ip' => '1.2.3.4',
            'enabled' => true,
            'last_heartbeat_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('redi:health');
        $output = Artisan::output();
        $this->assertStringContainsString('超过 15 分钟无心跳', $output);
        $this->assertStringContainsString('evilnode', $output);
        $this->assertStringNotContainsString("\r\n", $output);
    }

    public function test_health_json_reports_expired_cert(): void
    {
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'old.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'old.example',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:health', ['--json' => true])
            ->assertExitCode(1);
    }

    public function test_health_notify_exits_zero_when_webhook_delivered(): void
    {
        config(['redi.health_webhook_url' => 'https://hooks.example/health']);
        Http::fake(['https://hooks.example/*' => Http::response([], 200)]);

        \DB::table('redi_nodes')->insert([
            'name' => 'stale',
            'public_ip' => '1.2.3.4',
            'enabled' => true,
            'last_heartbeat_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:health', ['--notify' => true])->assertExitCode(0);
    }

    public function test_health_json_notify_exits_zero_when_webhook_delivered(): void
    {
        config(['redi.health_webhook_url' => 'https://hooks.example/health']);
        Http::fake(['https://hooks.example/*' => Http::response([], 200)]);

        \DB::table('redi_nodes')->insert([
            'name' => 'stale',
            'public_ip' => '1.2.3.4',
            'enabled' => true,
            'last_heartbeat_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:health', ['--json' => true, '--notify' => true])->assertExitCode(0);
    }

    public function test_health_notify_exits_one_when_webhook_not_configured(): void
    {
        config(['redi.health_webhook_url' => '']);

        \DB::table('redi_nodes')->insert([
            'name' => 'stale',
            'public_ip' => '1.2.3.4',
            'enabled' => true,
            'last_heartbeat_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:health', ['--notify' => true])->assertExitCode(1);
    }

    public function test_health_json_includes_queue_thresholds_in_summary(): void
    {
        config([
            'redi.health_queue_pending_threshold' => 77,
            'redi.health_queue_stale_hours' => 5,
            'redi.health_validating_stuck_hours' => 6,
            'redi.renew_validating_stuck_hours' => 7,
        ]);

        \DB::table('redi_nodes')->insert([
            'enabled' => true,
            'public_ip' => '1.2.3.4',
            'last_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('redi:health', ['--json' => true]);
        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame(77, $payload['summary']['health_queue_pending_threshold']);
        $this->assertSame(5, $payload['summary']['health_queue_stale_hours']);
        $this->assertSame(6, $payload['summary']['health_validating_stuck_hours']);
        $this->assertSame(7, $payload['summary']['health_renew_validating_stuck_hours']);
    }

    public function test_health_text_mode_prints_hints_without_failing(): void
    {
        \DB::table('redi_nodes')->insert([
            'enabled' => true,
            'public_ip' => '1.2.3.4',
            'last_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['queue.default' => 'database', 'cache.default' => 'file']);

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('redi:health');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('配置建议', Artisan::output());
    }
}
