<?php

namespace Tests\Unit;

use App\Models\RediAcmeChallenge;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Services\NodeAgentRevisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeAgentRevisionServiceTest extends TestCase
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

        Schema::create('redi_acme_challenges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain_name')->unique();
            $table->string('token');
            $table->text('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id')->nullable();
            $table->string('domain_name');
            $table->string('status')->default('active');
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function test_challenges_revision_ignores_empty_token_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'empty.example',
            'token' => '',
            'response_body' => '',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();
        RediAcmeChallenge::where('domain_name', 'empty.example')->delete();
        $b = $svc->build();

        $this->assertSame($a['challenges_revision'], $b['challenges_revision']);
    }

    public function test_challenges_revision_ignores_malformed_response_body_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'bad.example',
            'token' => 'ok-token',
            'response_body' => "bo\r\ndy",
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();
        RediAcmeChallenge::where('domain_name', 'bad.example')->delete();
        $b = $svc->build();

        $this->assertSame($a['challenges_revision'], $b['challenges_revision']);
    }

    public function test_challenges_revision_ignores_token_with_control_chars(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'bad.example',
            'token' => "ok-token\n",
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();
        RediAcmeChallenge::where('domain_name', 'bad.example')->delete();
        $b = $svc->build();

        $this->assertSame($a['challenges_revision'], $b['challenges_revision']);
    }

    public function test_certificates_revision_does_not_persist_domain_name_realign(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'good.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => 1,
            'domain_name' => 'stale.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => 1,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new NodeAgentRevisionService())->build();

        $this->assertSame('stale.example', RediDomainCert::query()->find($certId)->domain_name);
    }

    public function test_certificates_revision_scoped_to_node_deployment_rows(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'scoped.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => 1,
            'domain_name' => 'scoped.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => 2,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nodeOne = new RediNode();
        $nodeOne->id = 1;
        $nodeOne->exists = true;
        $nodeTwo = new RediNode();
        $nodeTwo->id = 2;
        $nodeTwo->exists = true;

        $svc = new NodeAgentRevisionService();
        $empty = $svc->build($nodeOne)['certificates_revision'];
        $withCert = $svc->build($nodeTwo)['certificates_revision'];

        $this->assertNotSame($withCert, $empty);
    }

    public function test_certificates_revision_changes_when_pem_ciphertext_changes_without_updated_at(): void
    {
        $stamp = '2020-01-01 00:00:00';
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'rotate.example',
            'status' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => 1,
            'domain_name' => 'rotate.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\nold\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\nold\n-----END PRIVATE KEY-----"),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => 1,
            'status' => 'pending',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $svc = new NodeAgentRevisionService();
        $before = $svc->build()['certificates_revision'];

        \DB::table('redi_domain_certs')->where('id', $certId)->update([
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\nnew\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\nnew\n-----END PRIVATE KEY-----"),
            'updated_at' => $stamp,
        ]);

        $after = $svc->build()['certificates_revision'];

        $this->assertNotSame($before, $after);
    }

    public function test_certificates_revision_changes_when_deployment_row_added(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'assigned.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => 1,
            'domain_name' => 'assigned.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new NodeAgentRevisionService();
        $before = $svc->build()['certificates_revision'];

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => 1,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = $svc->build()['certificates_revision'];

        $this->assertNotSame($before, $after);
    }

    public function test_certificates_revision_ignores_pullable_certs_without_deployment_rows(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'assigned.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignedId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => 1,
            'domain_name' => 'assigned.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $assignedId,
            'node_id' => 1,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'unassigned.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => 2,
            'domain_name' => 'unassigned.example',
            'status' => 'issued',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b = $svc->build();

        $this->assertSame($a['certificates_revision'], $b['certificates_revision']);
    }

    public function test_certificates_revision_ignores_control_char_domain_names(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'good.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => 1,
            'domain_name' => 'good.example',
            'status' => 'active',
            'certificate' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => 2,
            'domain_name' => "evil\x00.example",
            'status' => 'active',
            'certificate' => 'y',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b = $svc->build();

        $this->assertSame($a['certificates_revision'], $b['certificates_revision']);
    }

    public function test_rules_revision_ignores_control_char_original_urls(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'good.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => "bad\x00.example",
            'status' => 1,
            'force_https' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b = $svc->build();

        $this->assertSame($a['rules_revision'], $b['rules_revision']);
    }

    public function test_rules_revision_ignores_invalid_hosts(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'good.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'force_https' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b = $svc->build();

        $this->assertSame($a['rules_revision'], $b['rules_revision']);
    }
}
