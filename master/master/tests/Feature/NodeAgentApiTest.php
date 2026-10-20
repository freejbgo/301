<?php

namespace Tests\Feature;

use App\Models\RediAcmeChallenge;
use App\Models\RediDomainCert;
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

    public function test_challenges_synced_accepts_domain_with_scheme(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'valid.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['https://valid.example/']])
            ->assertOk()
            ->assertJsonPath('synced_count', 1);

        $this->assertDatabaseHas('redi_acme_challenge_node_sync', [
            'node_id' => $node->id,
            'domain_name' => 'valid.example',
        ]);
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

    public function test_challenges_synced_ignores_domain_when_challenge_token_has_control_chars(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'valid.example',
            'token' => "ok-token\n",
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['valid.example']])
            ->assertOk()
            ->assertJsonPath('synced_count', 0)
            ->assertJsonPath('ignored_count', 1);
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

    public function test_revision_certificates_revision_is_scoped_per_node(): void
    {
        [$nodeA, $tokenA] = $this->createNodeWithToken();
        $nodeB = new RediNode();
        $nodeB->name = 'n2';
        $nodeB->public_ip = '5.6.7.8';
        $nodeB->enabled = true;
        $nodeB->save();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'only-b.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'only-b.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($tokenA)->getJson('/api/nodes/revision')->json('certificates_revision');

        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodeB->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = $this->withToken($tokenA)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertSame($before, $after);
    }

    public function test_revision_combined_matches_nested_sha1_of_split_fields(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $payload = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $expected = sha1(json_encode([
            'challenges' => $payload['challenges_revision'],
            'rules' => $payload['rules_revision'],
            'certificates' => $payload['certificates_revision'],
        ]));
        $this->assertSame($expected, $payload['revision']);
        $this->assertNotSame('', $payload['challenges_revision']);
        $this->assertNotSame('', $payload['rules_revision']);
        $this->assertNotSame('', $payload['certificates_revision']);
    }

    public function test_revision_bumps_challenges_and_combined_when_ready_challenge_added(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        RediAcmeChallenge::create([
            'domain_name' => 'acme-rev.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
        $this->assertSame($before['certificates_revision'], $after['certificates_revision']);
        $this->assertSame($before['rules_revision'], $after['rules_revision']);
    }

    public function test_revision_unchanged_when_unpublishable_challenge_added(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        RediAcmeChallenge::create([
            'domain_name' => 'skip-rev.example',
            'token' => '',
            'response_body' => '',
            'expires_at' => now()->addHour(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_bumps_rules_and_combined_when_enabled_domain_added(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'new-rule.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
        $this->assertSame($before['certificates_revision'], $after['certificates_revision']);
        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
    }

    public function test_revision_bumps_rules_when_domain_disabled(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'drop-rule.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['status' => 2]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
    }

    public function test_revision_bumps_rules_when_domain_reenabled(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'reenable-rule.example',
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['status' => 1]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
    }

    public function test_revision_bumps_rules_when_original_url_host_changes(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'old-host.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['original_url' => 'new-host.example']);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
        $this->assertSame($before['certificates_revision'], $after['certificates_revision']);
        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
    }

    public function test_revision_unchanged_when_invalid_domain_host_added(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['rules_revision'], $after['rules_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_unchanged_when_disabled_domain_inserted(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'disabled-only.example',
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['rules_revision'], $after['rules_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_bumps_challenges_when_ready_challenge_updated(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $challenge = RediAcmeChallenge::create([
            'domain_name' => 'update-rev.example',
            'token' => 'tok',
            'response_body' => 'body-a',
            'expires_at' => now()->addHour(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        $challenge->response_body = 'body-b';
        $challenge->save();

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
        $this->assertSame($before['rules_revision'], $after['rules_revision']);
        $this->assertSame($before['certificates_revision'], $after['certificates_revision']);
    }

    public function test_revision_bumps_challenges_when_ready_challenge_deleted(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $challenge = RediAcmeChallenge::create([
            'domain_name' => 'delete-rev.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        $challenge->delete();

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);

        $challenges = $this->withToken($token)->getJson('/api/nodes/acme-challenges')->json('challenges');
        $this->assertSame([], $challenges);
    }

    public function test_revision_bumps_challenges_when_ready_challenge_expires(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $challenge = RediAcmeChallenge::create([
            'domain_name' => 'expire-rev.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        $challenge->expires_at = now()->subMinute();
        $challenge->save();

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
    }

    public function test_revision_unchanged_when_expired_challenge_inserted(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        RediAcmeChallenge::create([
            'domain_name' => 'already-expired.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->subHour(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_unchanged_when_challenge_has_control_chars_in_response_body(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        RediAcmeChallenge::create([
            'domain_name' => 'bad-body.example',
            'token' => 'ok-token',
            'response_body' => "bo\r\ndy",
            'expires_at' => now()->addHour(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_unchanged_when_challenge_token_has_control_chars(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        RediAcmeChallenge::create([
            'domain_name' => 'bad-token.example',
            'token' => "ok-token\n",
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_unchanged_when_challenge_domain_has_control_chars(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        RediAcmeChallenge::create([
            'domain_name' => "evil\x00.example",
            'token' => 'ok-token',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_unchanged_when_rules_domain_has_control_char_original_url(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => "bad\x00.example",
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertSame($before['rules_revision'], $after['rules_revision']);
        $this->assertSame($before['revision'], $after['revision']);
    }

    public function test_revision_bumps_rules_when_original_url_becomes_invalid_host(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'was-valid.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $beforeResponse->assertOk();
        $before = $beforeResponse->json();

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['original_url' => '1.2.3.4']);

        $afterResponse = $this->withToken($token)->getJson('/api/nodes/revision');
        $afterResponse->assertOk();
        $after = $afterResponse->json();

        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
    }

    public function test_revision_bumps_rules_and_certificates_when_force_https_toggled(): void
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
            'original_url' => 'https-toggle-rev.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'https-toggle-rev.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json();

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['force_https' => 1]);

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json();

        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);
        $this->assertNotSame($before['certificates_revision'], $after['certificates_revision']);
        $this->assertNotSame($before['revision'], $after['revision']);
    }

    public function test_revision_certificates_revision_drops_when_domain_disabled(): void
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
            'original_url' => 'off.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'off.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['status' => 2]);

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_drops_when_this_nodes_deployment_row_removed(): void
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
            'original_url' => 'unassign.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'unassign.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        \DB::table('redi_cert_deployments')->where('node_id', $node->id)->delete();

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_prunes_stale_ok_deployment_without_heartbeat(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'stale-ok.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'stale-ok.example',
            'status' => 'active',
            'certificate' => null,
            'private_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/nodes/revision')->assertOk();

        $this->assertDatabaseCount('redi_cert_deployments', 0);
    }

    public function test_revision_changes_after_failed_deployment_requeued(): void
    {
        [$node, $token] = $this->createNodeWithToken();
        $node->last_heartbeat_at = now();
        $node->save();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'requeue.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'requeue.example',
            'status' => RediDomainCert::STATUS_ISSUED,
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'failed',
            'last_error' => 'simulated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        app(CertificateDeployService::class)->requeueFailedDeployments((int) $node->id, null);

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
        $this->assertDatabaseHas('redi_cert_deployments', [
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'pending',
        ]);
    }

    public function test_revision_changes_when_repeat_failed_ack_updates_last_error(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $peer = new RediNode();
        $peer->name = 'n2';
        $peer->public_ip = '5.6.7.8';
        $peer->enabled = true;
        $peer->last_heartbeat_at = now();
        $peer->save();
        $node->last_heartbeat_at = now();
        $node->save();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 're-fail.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 're-fail.example',
            'status' => 'deploying',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            [
                'cert_id' => $certId,
                'node_id' => $node->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cert_id' => $certId,
                'node_id' => $peer->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => false, 'error' => 'first failure'])
            ->assertOk();

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => false, 'error' => 'second failure'])
            ->assertOk();

        $this->assertSame(
            'second failure',
            \DB::table('redi_cert_deployments')->where('cert_id', $certId)->where('node_id', $node->id)->value('last_error')
        );

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_on_ok_node_changes_when_peer_failed_ack_sets_cert_last_error(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$nodeOk, $tokenOk] = $this->createNodeWithToken();
        [$nodeFail, $tokenFail] = $this->createNodeWithToken();
        $nodeOk->last_heartbeat_at = now();
        $nodeOk->save();
        $nodeFail->last_heartbeat_at = now();
        $nodeFail->save();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'peer-fail.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'peer-fail.example',
            'status' => 'deploying',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            [
                'cert_id' => $certId,
                'node_id' => $nodeOk->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cert_id' => $certId,
                'node_id' => $nodeFail->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->withToken($tokenOk)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])
            ->assertOk();

        $before = $this->withToken($tokenOk)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($tokenFail)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => false, 'error' => 'peer install failed'])
            ->assertOk();

        $this->assertSame(
            '部分节点安装失败，其余节点仍保留该证书',
            \DB::table('redi_domain_certs')->where('id', $certId)->value('last_error')
        );
        $this->assertSame('ok', \DB::table('redi_cert_deployments')->where('cert_id', $certId)->where('node_id', $nodeOk->id)->value('status'));

        $after = $this->withToken($tokenOk)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_on_ok_node_changes_when_peer_failure_requeued(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$nodeOk, $tokenOk] = $this->createNodeWithToken();
        [$nodeFail, $tokenFail] = $this->createNodeWithToken();
        $nodeOk->last_heartbeat_at = now();
        $nodeOk->save();
        $nodeFail->last_heartbeat_at = now();
        $nodeFail->save();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'peer-req.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'peer-req.example',
            'status' => 'deploying',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            [
                'cert_id' => $certId,
                'node_id' => $nodeOk->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cert_id' => $certId,
                'node_id' => $nodeFail->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->withToken($tokenOk)->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])->assertOk();
        $this->withToken($tokenFail)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => false, 'error' => 'peer fail'])
            ->assertOk();

        $before = $this->withToken($tokenOk)->getJson('/api/nodes/revision')->json('certificates_revision');

        app(CertificateDeployService::class)->requeueFailedDeployments((int) $nodeFail->id, (int) $certId);

        $after = $this->withToken($tokenOk)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
        $this->assertSame('pending', \DB::table('redi_cert_deployments')->where('cert_id', $certId)->where('node_id', $nodeFail->id)->value('status'));
        $this->assertSame('ok', \DB::table('redi_cert_deployments')->where('cert_id', $certId)->where('node_id', $nodeOk->id)->value('status'));
    }

    public function test_revision_on_ok_node_changes_when_peer_recovers_after_failed_ack(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$nodeOk, $tokenOk] = $this->createNodeWithToken();
        [$nodeFail, $tokenFail] = $this->createNodeWithToken();
        $nodeOk->last_heartbeat_at = now();
        $nodeOk->save();
        $nodeFail->last_heartbeat_at = now();
        $nodeFail->save();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'peer-recover.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'peer-recover.example',
            'status' => 'deploying',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            [
                'cert_id' => $certId,
                'node_id' => $nodeOk->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cert_id' => $certId,
                'node_id' => $nodeFail->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->withToken($tokenOk)->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])->assertOk();
        $this->withToken($tokenFail)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => false, 'error' => 'peer fail'])
            ->assertOk();

        $before = $this->withToken($tokenOk)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($tokenFail)->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])->assertOk();

        $this->assertNull(\DB::table('redi_domain_certs')->where('id', $certId)->value('last_error'));

        $after = $this->withToken($tokenOk)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_changes_when_ok_ack_clears_stale_deployment_last_error(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $node->last_heartbeat_at = now();
        $node->save();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'stale-dep-err.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'stale-dep-err.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'last_error' => 'stale',
            'deployed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($token)->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])->assertOk();

        $this->assertNull(\DB::table('redi_cert_deployments')->where('cert_id', $certId)->value('last_error'));

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_changes_after_failed_ack(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'ack-fail.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'ack-fail.example',
            'status' => 'deploying',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
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

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => false, 'error' => 'install failed'])
            ->assertOk();

        $this->assertSame('failed', \DB::table('redi_cert_deployments')->where('cert_id', $certId)->value('status'));
        $this->assertSame('failed', \DB::table('redi_domain_certs')->where('id', $certId)->value('status'));

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_revision_changes_after_successful_ack(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $node->last_heartbeat_at = now();
        $node->save();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'ack-rev.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'ack-rev.example',
            'status' => 'deploying',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
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

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])
            ->assertOk();

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
        $this->assertSame('ok', \DB::table('redi_cert_deployments')->where('cert_id', $certId)->value('status'));
    }

    public function test_revision_certificates_revision_drops_when_pem_cleared(): void
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
            'original_url' => 'pem-gone.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'pem-gone.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $cert = RediDomainCert::query()->find($certId);
        $cert->certificate = null;
        $cert->private_key = null;
        $cert->save();

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
        $this->assertDatabaseCount('redi_cert_deployments', 0);
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
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
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

    public function test_revision_certificates_unchanged_for_unassigned_pullable_cert(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [, $token] = $this->createNodeWithToken();
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'rev.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'rev.example',
            'status' => 'issued',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'other.example',
            'status' => 'issued',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertSame($before, $after);
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

    public function test_register_bumps_certificates_revision_when_sync_adds_deployment(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'rev-reg.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'rev-reg.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->withToken($token)
            ->postJson('/api/nodes/register', ['version' => '0.1.3'])
            ->assertOk();

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');

        $this->assertNotSame($before, $after);
    }

    public function test_register_lists_cert_on_certificates_after_revision_bumps(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'pull-after-reg.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'pull-after-reg.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');
        $this->withToken($token)->postJson('/api/nodes/register', ['version' => '0.1.3'])->assertOk();
        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json('certificates_revision');
        $this->assertNotSame($before, $after);

        $ids = array_column(
            $this->withToken($token)->getJson('/api/nodes/certificates')->json('certificates'),
            'cert_id'
        );
        $this->assertSame([$certId], $ids);
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

    public function test_heartbeat_prunes_non_pullable_pending_deployment(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'edge.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'edge.example',
            'status' => 'deploying',
            'last_error' => '等待节点拉取证书',
            'certificate' => null,
            'private_key' => null,
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

        $this->withToken($token)->postJson('/api/nodes/heartbeat', [])->assertOk();

        $this->assertDatabaseCount('redi_cert_deployments', 0);
        $this->assertSame(
            'issued',
            (string) \DB::table('redi_domain_certs')->where('id', $certId)->value('status')
        );
    }

    public function test_redirect_rules_normalizes_scheme_in_original_url(): void
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
            'original_url' => 'https://Canon.Example/',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rules = $this->withToken($token)->getJson('/api/nodes/redirect-rules')->json('rules');
        $this->assertCount(1, $rules);
        $this->assertSame('canon.example', $rules[0]['original_url']);
    }

    public function test_redirect_rules_lists_domain_when_rules_revision_bumps(): void
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

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'new-rule.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);

        $rules = $this->withToken($token)->getJson('/api/nodes/redirect-rules')->json('rules');
        $this->assertCount(1, $rules);
        $this->assertSame('new-rule.example', $rules[0]['original_url']);
        $this->assertFalse($rules[0]['force_https']);
    }

    public function test_redirect_rules_reflects_force_https_toggle(): void
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

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'https-toggle-rules.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['force_https' => 1]);
        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $this->assertNotSame($before['rules_revision'], $after['rules_revision']);

        $rules = $this->withToken($token)->getJson('/api/nodes/redirect-rules')->json('rules');
        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]['force_https']);
    }

    public function test_redirect_rules_ordered_by_domain_id(): void
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
                'original_url' => 'zzz-last-alpha.example',
                'status' => 1,
                'force_https' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'original_url' => 'aaa-first-alpha.example',
                'status' => 1,
                'force_https' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $hosts = array_column(
            $this->withToken($token)->getJson('/api/nodes/redirect-rules')->json('rules'),
            'original_url'
        );
        $this->assertSame(['zzz-last-alpha.example', 'aaa-first-alpha.example'], $hosts);
    }

    public function test_acme_challenges_lists_ready_row_when_challenges_revision_bumps(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json();

        RediAcmeChallenge::create([
            'domain_name' => 'acme-api.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $this->assertNotSame($before['challenges_revision'], $after['challenges_revision']);

        $challenges = $this->withToken($token)->getJson('/api/nodes/acme-challenges')->json('challenges');
        $this->assertCount(1, $challenges);
        $this->assertSame('acme-api.example', $challenges[0]['domain_name']);
        $this->assertSame('tok', $challenges[0]['token']);
    }

    public function test_acme_challenges_omit_expired_rows(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'stale.example',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->subHour(),
        ]);

        $challenges = $this->withToken($token)->getJson('/api/nodes/acme-challenges')->json('challenges');
        $this->assertSame([], $challenges);
    }

    public function test_acme_challenges_normalizes_scheme_in_domain_name(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'https://Canon-Acme.Example/',
            'token' => 'tok',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $challenges = $this->withToken($token)->getJson('/api/nodes/acme-challenges')->json('challenges');
        $this->assertCount(1, $challenges);
        $this->assertSame('canon-acme.example', $challenges[0]['domain_name']);
    }

    public function test_acme_challenges_ordered_by_challenge_id(): void
    {
        [, $token] = $this->createNodeWithToken();

        RediAcmeChallenge::create([
            'domain_name' => 'zzz.example',
            'token' => 'tok-z',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'aaa.example',
            'token' => 'tok-a',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $names = array_column(
            $this->withToken($token)->getJson('/api/nodes/acme-challenges')->json('challenges'),
            'domain_name'
        );
        $this->assertSame(['zzz.example', 'aaa.example'], $names);
    }

    public function test_certificates_ordered_by_cert_id(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $domainA = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'cert-z.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainB = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'cert-a.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certZ = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainA,
            'domain_name' => 'cert-z.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\nz\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\nz\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certA = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainB,
            'domain_name' => 'cert-a.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\na\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\na\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$certZ, $certA] as $certId) {
            \DB::table('redi_cert_deployments')->insert([
                'cert_id' => $certId,
                'node_id' => $node->id,
                'status' => 'ok',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ids = array_column(
            $this->withToken($token)->getJson('/api/nodes/certificates')->json('certificates'),
            'cert_id'
        );
        $this->assertSame([$certZ, $certA], $ids);
    }

    public function test_certificates_response_includes_force_https(): void
    {
        [$node, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(true);
            $table->timestamps();
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'force-https-cert.example',
            'status' => 1,
            'force_https' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'force-https-cert.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $node->id,
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certs = $this->withToken($token)->getJson('/api/nodes/certificates')->json('certificates');
        $this->assertCount(1, $certs);
        $this->assertTrue($certs[0]['force_https']);
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

    public function test_certificates_realigns_control_char_domain_name_from_domain(): void
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

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => "evil\x00.example",
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
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

        $response = $this->withToken($token)->getJson('/api/nodes/certificates');
        $response->assertOk();
        $ids = array_column($response->json('certificates'), 'cert_id');
        $this->assertContains($certId, $ids);
        $this->assertSame('cert.example', \DB::table('redi_domain_certs')->where('id', $certId)->value('domain_name'));
    }

    public function test_certificates_domain_field_uses_acme_host_key(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'https://cert.example/',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => 'cert.example',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
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

        $response = $this->withToken($token)->getJson('/api/nodes/certificates');
        $response->assertOk();
        $row = collect($response->json('certificates'))->firstWhere('cert_id', $certId);
        $this->assertNotNull($row);
        $this->assertSame('cert.example', $row['domain']);
        $this->assertStringNotContainsString('://', $row['domain']);
    }

    public function test_certificates_omit_when_domain_original_url_invalid(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        [$node, $token] = $this->createNodeWithToken();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domainId,
            'domain_name' => '1.2.3.4',
            'status' => 'active',
            'certificate' => Crypt::encryptString("-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----"),
            'private_key' => Crypt::encryptString("-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----"),
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

        $response = $this->withToken($token)->getJson('/api/nodes/certificates');
        $response->assertOk();
        $ids = array_column($response->json('certificates'), 'cert_id');
        $this->assertNotContains($certId, $ids);
    }

    public function test_redirect_rules_omit_disabled_domains(): void
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
                'original_url' => 'live.example',
                'status' => 1,
                'force_https' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'original_url' => 'paused.example',
                'status' => 0,
                'force_https' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->withToken($token)->getJson('/api/nodes/redirect-rules');
        $response->assertOk();
        $hosts = array_column($response->json('rules'), 'original_url');
        $this->assertSame(['live.example'], $hosts);
    }

    public function test_redirect_rules_and_rules_revision_drop_when_domain_disabled(): void
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

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'toggle-off.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeRev = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $beforeRules = $this->withToken($token)->getJson('/api/nodes/redirect-rules')->json('rules');
        $this->assertCount(1, $beforeRules);

        \DB::table('redi_admin_domain')->where('id', $domainId)->update(['status' => 0]);

        $afterRev = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $afterRules = $this->withToken($token)->getJson('/api/nodes/redirect-rules')->json('rules');

        $this->assertNotSame($beforeRev['rules_revision'], $afterRev['rules_revision']);
        $this->assertNotSame($beforeRev['revision'], $afterRev['revision']);
        $this->assertSame([], $afterRules);
    }

    public function test_revision_unchanged_when_challenge_token_fails_http01_format(): void
    {
        [, $token] = $this->createNodeWithToken();

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        $before = $this->withToken($token)->getJson('/api/nodes/revision')->json();

        RediAcmeChallenge::create([
            'domain_name' => 'malformed-token.example',
            'token' => '../evil',
            'response_body' => 'body',
            'expires_at' => now()->addHour(),
        ]);

        $after = $this->withToken($token)->getJson('/api/nodes/revision')->json();
        $this->assertSame($before['challenges_revision'], $after['challenges_revision']);
        $this->assertSame($before['revision'], $after['revision']);

        $challenges = $this->withToken($token)->getJson('/api/nodes/acme-challenges')->json('challenges');
        $this->assertSame([], $challenges);
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
