<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Services\CertificateDeployService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CertificateDeployServiceTest extends TestCase
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
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->string('status')->default('active');
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

    public function test_sync_deployments_for_node_creates_pending_rows(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CertificateDeployService::class)->syncDeploymentsForNode($node);

        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
        ]);
    }

    public function test_mark_node_ack_is_idempotent_when_already_ok(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cert = RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => RediDomainCert::STATUS_DEPLOYING,
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'ok',
            'deployed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(CertificateDeployService::class);
        $this->assertTrue($svc->markNodeAck($cert, $node, true));
    }
}
