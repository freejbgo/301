<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use Illuminate\Database\Schema\Blueprint;
use App\Support\RediErrorMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequeueFailedDeploymentsCommandTest extends TestCase
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
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_command_reports_when_no_failed_rows(): void
    {
        $exit = Artisan::call('redi:requeue-failed-deployments');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(RediErrorMessage::get('cli.requeue_none'), Artisan::output());
    }

    public function test_command_rejects_invalid_node_id(): void
    {
        $exit = Artisan::call('redi:requeue-failed-deployments', ['--node' => '0']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString(RediErrorMessage::get('cli.requeue_node_id'), Artisan::output());
    }

    public function test_command_rejects_invalid_cert_id(): void
    {
        $exit = Artisan::call('redi:requeue-failed-deployments', ['--cert' => '0']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString(RediErrorMessage::get('cli.requeue_cert_id'), Artisan::output());
    }

    public function test_command_skips_failed_on_disabled_node(): void
    {
        $nodeId = \DB::table('redi_nodes')->insertGetId([
            'enabled' => false,
            'public_ip' => '1.2.3.4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => RediDomainCert::STATUS_FAILED,
            'certificate' => \Illuminate\Support\Facades\Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => \Illuminate\Support\Facades\Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodeId,
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('redi:requeue-failed-deployments');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(RediErrorMessage::get('cli.requeue_none'), Artisan::output());
        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'status' => 'failed',
        ]);
    }

    public function test_command_requeues_failed_deployments(): void
    {
        $nodeId = \DB::table('redi_nodes')->insertGetId([
            'enabled' => true,
            'public_ip' => '1.2.3.4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => RediDomainCert::STATUS_FAILED,
            'certificate' => \Illuminate\Support\Facades\Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => \Illuminate\Support\Facades\Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodeId,
            'status' => 'failed',
            'last_error' => 'pem error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('redi:requeue-failed-deployments', ['--node' => (string) $nodeId]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(RediErrorMessage::get('cli.requeue_done', ['count' => 1]), Artisan::output());
        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'node_id' => $nodeId,
            'status' => 'pending',
        ]);
    }
}
