<?php

namespace Tests\Feature;

use App\Models\RediAcmeChallenge;
use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
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
            $table->text('certificate_encrypted')->nullable();
            $table->text('private_key_encrypted')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('acked_at')->nullable();
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
        [, $token] = $this->createNodeWithToken();

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

    public function test_challenges_synced_ignores_unknown_domain(): void
    {
        [, $token] = $this->createNodeWithToken();

        $response = $this->withToken($token)
            ->postJson('/api/nodes/challenges-synced', ['domains' => ['not-in-challenges.example']])
            ->assertOk();

        $response->assertJsonPath('synced_count', 0);
    }

    public function test_ack_forbidden_without_deployment_row(): void
    {
        [, $token] = $this->createNodeWithToken();

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_name' => 'valid.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/nodes/ack', ['cert_id' => $certId, 'ok' => true])
            ->assertStatus(403);
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
}
