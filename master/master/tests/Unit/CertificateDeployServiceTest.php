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
            $table->unique(['cert_id', 'node_id']);
        });
    }

    public function test_sync_deployments_realigns_stale_cert_domain_name(): void
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
            'domain_name' => 'stale.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CertificateDeployService::class)->syncDeploymentsForNode($node);

        $this->assertSame('edge.example', RediDomainCert::query()->find($certId)->domain_name);
        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
        ]);
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

    public function test_requeue_failed_deployments_realigns_cert_domain_name(): void
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
            'domain_name' => 'stale.example',
            'status' => RediDomainCert::STATUS_FAILED,
        ]);
        $cert->setCertificatePem("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----");
        $cert->setPrivateKeyPem("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");
        $cert->save();
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'failed',
            'last_error' => 'pem error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CertificateDeployService::class)->requeueFailedDeployments((int) $node->id, null);

        $this->assertSame('edge.example', $cert->fresh()->domain_name);
    }

    public function test_requeue_failed_deployments_resets_status(): void
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
            'status' => RediDomainCert::STATUS_FAILED,
        ]);
        $cert->setCertificatePem("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----");
        $cert->setPrivateKeyPem("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");
        $cert->save();
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'failed',
            'last_error' => 'pem error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = app(CertificateDeployService::class)->requeueFailedDeployments((int) $node->id, null);
        $this->assertSame(1, $count);
        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'pending',
        ]);
        $cert->refresh();
        $this->assertSame(RediDomainCert::STATUS_DEPLOYING, $cert->status);
    }

    public function test_mark_node_ack_rejects_ok_when_admin_domain_invalid(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'not a valid host!!!',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cert = RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => 'orphan.example',
            'status' => RediDomainCert::STATUS_DEPLOYING,
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(CertificateDeployService::class);
        $this->assertTrue($svc->markNodeAck($cert, $node, true));

        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'failed',
        ]);
        $deploymentError = (string) \DB::table('redi_cert_deployments')
            ->where('cert_id', $cert->id)
            ->where('node_id', $node->id)
            ->value('last_error');
        $this->assertStringContainsString('不一致', $deploymentError);
        $this->assertSame(RediDomainCert::STATUS_FAILED, $cert->fresh()->status);
    }

    public function test_mark_node_ack_rejects_ok_when_domain_disabled(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cert = RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => RediDomainCert::STATUS_DEPLOYING,
        ]);
        $cert->setCertificatePem("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----");
        $cert->setPrivateKeyPem("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");
        $cert->save();
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(CertificateDeployService::class);
        $this->assertTrue($svc->markNodeAck($cert, $node, true));

        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'failed',
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

    public function test_sync_realigns_control_char_cert_domain_name_from_domain(): void
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
            'domain_name' => "evil\x00.example",
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CertificateDeployService::class)->syncDeploymentsForNode($node);

        $this->assertSame('edge.example', RediDomainCert::query()->find($certId)->domain_name);
        $this->assertDatabaseCount('redi_cert_deployments', 1);
    }

    public function test_sync_skips_certs_with_invalid_domain_name(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => '1.2.3.4',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CertificateDeployService::class)->syncDeploymentsForNode($node);

        $this->assertDatabaseCount('redi_cert_deployments', 0);
    }

    public function test_requeue_failed_deployments_skips_invalid_admin_domain(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'not a valid host!!!',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'orphan.example',
            'status' => RediDomainCert::STATUS_FAILED,
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'failed',
            'last_error' => 'pem error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = app(CertificateDeployService::class)->requeueFailedDeployments((int) $node->id, null);

        $this->assertSame(0, $count);
        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'failed',
        ]);
    }

    public function test_queue_deploy_skips_when_cert_does_not_match_admin_domain(): void
    {
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'not a valid host!!!',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'orphan.example',
            'status' => 'issued',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);

        $cert = RediDomainCert::query()->find($certId);
        $queued = app(CertificateDeployService::class)->queueDeploy($cert);

        $this->assertFalse($queued);
        $this->assertDatabaseCount('redi_cert_deployments', 0);
        $this->assertStringContainsString('跳过部署', (string) $cert->fresh()->last_error);
        $this->assertSame('issued', $cert->fresh()->status);
    }

    public function test_cert_is_node_pullable_requires_pem_domain_match_and_status(): void
    {
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
        $this->assertFalse(CertificateDeployService::certIsNodePullable($cert));

        $cert->setCertificatePem("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----");
        $cert->setPrivateKeyPem("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");
        $cert->save();
        $this->assertTrue(CertificateDeployService::certIsNodePullable($cert->fresh()));

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['status' => 0]);
        $this->assertFalse(CertificateDeployService::certIsNodePullable($cert->fresh()));
    }

    public function test_count_actionable_deployments_excludes_non_pullable_certs(): void
    {
        $node = RediNode::query()->create(['enabled' => true, 'public_ip' => '1.2.3.4']);
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cert = RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => RediDomainCert::STATUS_DEPLOYING,
        ]);
        $cert->setCertificatePem("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----");
        $cert->setPrivateKeyPem("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");
        $cert->save();
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $cert->id,
            'node_id' => $node->id,
            'status' => 'failed',
            'last_error' => 'simulated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, CertificateDeployService::countActionableDeployments('failed'));
        $this->assertSame(0, CertificateDeployService::countActionableDeployments('pending'));
    }
}
