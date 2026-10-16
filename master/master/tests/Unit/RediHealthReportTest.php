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
}
