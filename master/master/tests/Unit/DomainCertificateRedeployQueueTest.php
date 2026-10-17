<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Services\DomainCertificateRedeployQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainCertificateRedeployQueueTest extends TestCase
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

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('original_url');
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name');
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->string('status')->default('active');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('enabled')->default(true);
            $table->string('public_ip', 45)->nullable();
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
            $table->unique(['cert_id', 'node_id']);
        });
    }

    public function test_redeploy_owned_queues_deploy_for_readable_cert(): void
    {
        \DB::table('redi_nodes')->insert([
            'enabled' => true,
            'public_ip' => '1.2.3.4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'admin_id' => 10,
            'status' => 1,
            'original_url' => 'ok.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'ok.example',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = DomainCertificateRedeployQueue::redeployOwned([$domainId], 10, false);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('redi_cert_deployments', ['status' => 'pending']);
    }

    public function test_redeploy_skips_when_cert_domain_drift(): void
    {
        \DB::table('redi_nodes')->insert([
            'enabled' => true,
            'public_ip' => '1.2.3.4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'admin_id' => 10,
            'status' => 1,
            'original_url' => 'not a valid host!!!',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'ok.example',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = DomainCertificateRedeployQueue::redeployOwned([$domainId], 10, false);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('redi_cert_deployments', 0);
    }

    public function test_redeploy_skips_other_tenant(): void
    {
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'admin_id' => 20,
            'status' => 1,
            'original_url' => 'other.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = DomainCertificateRedeployQueue::redeployOwned([$domainId], 10, false);
        $this->assertSame(0, $result['queued']);
    }
}
