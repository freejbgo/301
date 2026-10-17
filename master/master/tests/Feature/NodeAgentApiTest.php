<?php

namespace Tests\Feature;

use App\Models\RediAcmeChallenge;
use App\Models\RediNode;
use App\Services\CertificateDeployService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeAgentApiTest extends TestCase
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
            $table->text('extra_ips')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('api_token_hash')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_acme_challenges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain_name')->unique();
            $table->string('token');
            $table->text('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['node_id', 'domain_name']);
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id')->nullable();
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

    protected function createNodeWithToken(): array
    {
        $node = new RediNode();
        $node->name = 'n1';
        $node->public_ip = '1.2.3.4';
        $node->enabled = true;
        $node->save();
        $plain = $node->issueApiTokenPlain();
        $node->save();

        return [$node, $plain];
    }

    public function test_challenges_synced_ignores_empty_challenge_token(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'empty.example',
            'token' => '',
            'response_body' => '',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['empty.example']])
            ->assertOk();

        $response->assertJsonPath('synced_count', 0);
        $response->assertJsonPath('ignored_count', 1);

        $this->assertDatabaseMissing('redi_acme_challenge_node_sync', [
            'domain_name' => 'empty.example',
        ]);
    }

    public function test_challenges_synced_records_valid_domain(): void
    {
        [$node, $token] = $this->createNodeWithToken();
        $node->last_heartbeat_at = now()->subHour();
        $node->save();

        RediAcmeChallenge::create([
            'domain_name' => 'valid.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['valid.example']])
            ->assertOk();

        $this->assertDatabaseHas('redi_acme_challenge_node_sync', [
            'domain_name' => 'valid.example',
        ]);
        $node->refresh();
        $this->assertTrue($node->last_heartbeat_at->greaterThan(now()->subMinutes(5)));
    }

    public function test_acme_challenges_omits_empty_token_rows(): void
    {
        [, $token] = $this->createNodeWithToken();

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

        $response = $this->withToken($token)->getJson('/api/nodes/acme-challenges');
        $response->assertOk();
        $names = array_column($response->json('challenges'), 'domain_name');
        $this->assertContains('good.example', $names);
        $this->assertNotContains('empty.example', $names);
    }

    public function test_acme_challenges_omits_control_chars_in_domain_name(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => "bad\x00.example",
            'token' => 'ok-token',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 'ok-token',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/acme-challenges');
        $response->assertOk();
        $names = array_column($response->json('challenges'), 'domain_name');
        $this->assertContains('good.example', $names);
        $this->assertNotContains('bad.example', $names);
    }

    public function test_acme_challenges_omits_control_chars_in_token(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'bad.example',
            'token' => "ok-token\n",
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 'ok-token',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/acme-challenges');
        $response->assertOk();
        $names = array_column($response->json('challenges'), 'domain_name');
        $this->assertContains('good.example', $names);
        $this->assertNotContains('bad.example', $names);
    }

    public function test_acme_challenges_omits_control_chars_in_response_body(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'bad.example',
            'token' => 'ok-token',
            'response_body' => "bo\r\ndy",
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 'ok-token',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/acme-challenges');
        $response->assertOk();
        $names = array_column($response->json('challenges'), 'domain_name');
        $this->assertContains('good.example', $names);
        $this->assertNotContains('bad.example', $names);
    }

    public function test_acme_challenges_omits_trailing_newline_in_response_body(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'trail.example',
            'token' => 'ok-token',
            'response_body' => "body\n",
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/acme-challenges');
        $response->assertOk();
        $names = array_column($response->json('challenges'), 'domain_name');
        $this->assertNotContains('trail.example', $names);
    }

    public function test_challenges_synced_ignores_malformed_response_body(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'bad.example',
            'token' => 'ok-token',
            'response_body' => "bo\r\ndy",
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['bad.example']])
            ->assertOk();

        $response->assertJsonPath('synced_count', 0);
        $response->assertJsonPath('ignored_count', 1);
    }

    public function test_challenges_synced_ignores_unknown_domain(): void
    {
        [, $token] = $this->createNodeWithToken();

        $response = $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['not-in-challenges.example']])
            ->assertOk();

        $response->assertJsonPath('synced_count', 0);
        $response->assertJsonPath('ignored_count', 1);
    }

    public function test_challenges_synced_blank_entries_do_not_inflate_ignored_count(): void
    {
        [, $token] = $this->createNodeWithToken();

        $response = $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['', 'not-in-challenges.example']])
            ->assertOk();

        $response->assertJsonPath('synced_count', 0);
        $response->assertJsonPath('ignored_count', 1);
    }

    public function test_challenges_synced_rejects_non_array_domains_with_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid domains');
    }

    public function test_challenges_synced_ignores_non_string_domain_entries(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', [
                'domains' => [['nested'], 'not-in-challenges.example'],
            ])
            ->assertOk()
            ->assertJsonPath('ignored_count', 2)
            ->assertJsonPath('synced_count', 0);
    }

    public function test_challenges_synced_rejects_control_chars_in_domain_with_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ["sync\x00.example"]])
            ->assertStatus(422)
            ->assertJsonPath('domain', 'sync.example');
    }

    public function test_challenges_synced_rejects_trailing_newline_in_domain_with_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['valid.example'."\n"]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid domain');
    }

    public function test_challenges_synced_rejects_invalid_host_with_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['not a valid host!!!']])
            ->assertStatus(422);
    }

    public function test_challenges_synced_rejects_overlong_domain_with_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => [str_repeat('a', 254).'.com']])
            ->assertStatus(422);
    }

    public function test_certificates_only_returns_certs_assigned_to_node(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'cert.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignedId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'cert.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'other.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest2\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest2\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $assignedId,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/certificates');
        $response->assertOk();
        $ids = array_column($response->json('certificates'), 'cert_id');
        $this->assertContains($assignedId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    public function test_ack_returns_409_when_mark_not_applied(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_name' => 'valid.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(CertificateDeployService::class, function ($mock) {
            $mock->shouldReceive('markNodeAck')->once()->andReturn(false);
        });

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])
            ->assertStatus(409);
    }

    public function test_ack_missing_cert_id_returns_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['ok' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid cert_id');
    }

    public function test_ack_rejects_malformed_cert_id_with_422(): void
    {
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => '123abc', 'ok' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid cert_id');
    }

    public function test_ack_success_ignores_error_field(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'valid.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'valid.example',
            'status' => 'deploying',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
            'last_error' => 'old failure',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', [
                'cert_id' => $certId,
                'ok' => true,
                'error' => 'should not be stored',
            ])
            ->assertOk();

        $row = \DB::table('redi_cert_deployments')->where('cert_id', $certId)->first();
        $this->assertSame('ok', $row->status);
        $this->assertNull($row->last_error);
    }

    public function test_ack_treats_string_false_as_failure(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'valid.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'valid.example',
            'status' => 'deploying',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', [
                'cert_id' => $certId,
                'ok' => 'false',
                'error' => 'install failed',
            ])
            ->assertOk();

        $this->assertSame(
            'failed',
            \DB::table('redi_cert_deployments')->where('cert_id', $certId)->value('status')
        );
        $this->assertSame(
            'install failed',
            \DB::table('redi_cert_deployments')->where('cert_id', $certId)->value('last_error')
        );
    }

    public function test_ack_rejects_non_string_error_with_422(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'valid.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'valid.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', [
                'cert_id' => $certId,
                'ok' => false,
                'error' => ['detail' => 'boom'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid error');
    }

    public function test_ack_rejects_trailing_newline_in_error_with_422(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'valid.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'valid.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', [
                'cert_id' => $certId,
                'ok' => false,
                'error' => 'disk full'."\n",
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid error');
    }

    public function test_ack_rejects_control_chars_in_error_with_422(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'valid.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'valid.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', [
                'cert_id' => $certId,
                'ok' => false,
                'error' => "disk\r\nfull",
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'invalid error');
    }

    public function test_ack_ignored_without_deployment_row(): void
    {
        [, $token] = $this->createNodeWithToken();

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_name' => 'valid.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])
            ->assertOk()
            ->assertJsonPath('ignored', true);
    }

    public function test_register_backfills_cert_deployments_for_active_certs(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'reg.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'reg.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/register', ['version' => '0.1.3'])
            ->assertOk();

        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
        ]);
    }

    public function test_register_preserves_agent_version_when_version_key_absent(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $node->agent_version = '0.1.3';
        $node->save();

        $this->withToken($token)
            ->postJson('/api/nodes/register', [])
            ->assertOk();

        $node->refresh();
        $this->assertSame('0.1.3', $node->agent_version);
    }

    public function test_heartbeat_rejects_version_with_control_chars_with_426(): void
    {
        config(['redi.min_agent_version' => '0.1.2']);
        [, $token] = $this->createNodeWithToken();

        $this->withToken($token)
            ->postJson('/api/nodes/heartbeat', ['version' => '0.1.3'."\0"])
            ->assertStatus(426);
    }

    public function test_heartbeat_preserves_agent_version_when_version_key_absent(): void
    {
        [$node, $token] = $this->createNodeWithToken();
        $node->agent_version = '0.1.3';
        $node->save();

        $this->withToken($token)
            ->postJson('/api/nodes/heartbeat', [])
            ->assertOk();

        $node->refresh();
        $this->assertSame('0.1.3', $node->agent_version);
    }

    public function test_redirect_rules_shape(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id')->default(1);
            $table->string('original_url');
            $table->string('skip_url')->default('http://example.com');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'foo.example',
            'status' => 1,
            'force_https' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/redirect-rules');
        $response->assertOk();
        $rules = $response->json('rules');
        $this->assertCount(1, $rules);
        $this->assertArrayHasKey('original_url', $rules[0]);
        $this->assertArrayHasKey('force_https', $rules[0]);
        $this->assertArrayNotHasKey('skip_url', $rules[0]);
    }

    public function test_redirect_rules_omit_hosts_with_control_chars(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id')->default(1);
            $table->string('original_url');
            $table->string('skip_url')->default('http://example.com');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        \DB::table('redi_admin_domain')->insert([
            [
                'original_url' => 'good.example',
                'status' => 1,
                'force_https' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'original_url' => "bad\x00.example",
                'status' => 1,
                'force_https' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/redirect-rules');
        $response->assertOk();
        $rules = $response->json('rules');
        $this->assertCount(1, $rules);
        $this->assertSame('good.example', $rules[0]['original_url']);
    }

    public function test_certificates_omit_control_char_domain_names(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'cert.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $goodId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'cert.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $badId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => "evil\x00.example",
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest2\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest2\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_cert_deployments')->insert([
            ['cert_id' => $goodId, 'node_id' => $node->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['cert_id' => $badId, 'node_id' => $node->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/certificates');
        $response->assertOk();
        $ids = array_column($response->json('certificates'), 'cert_id');
        $this->assertContains($goodId, $ids);
        $this->assertNotContains($badId, $ids);
    }

    public function test_redirect_rules_omit_invalid_hosts(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id')->default(1);
            $table->string('original_url');
            $table->string('skip_url')->default('http://example.com');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        \DB::table('redi_admin_domain')->insert([
            [
                'original_url' => 'good.example',
                'status' => 1,
                'force_https' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'original_url' => '1.2.3.4',
                'status' => 1,
                'force_https' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/redirect-rules');
        $response->assertOk();
        $rules = $response->json('rules');
        $this->assertCount(1, $rules);
        $this->assertSame('good.example', $rules[0]['original_url']);
    }
}
