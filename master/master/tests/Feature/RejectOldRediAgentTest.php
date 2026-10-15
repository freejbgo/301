<?php

namespace Tests\Feature;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RejectOldRediAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['redi.min_agent_version' => '0.1.2']);
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
    }

    public function test_revision_returns_426_without_agent_version(): void
    {
        $node = new RediNode();
        $node->name = 'n1';
        $node->public_ip = '1.2.3.4';
        $node->enabled = true;
        $node->save();
        $plain = $node->issueApiTokenPlain();
        $node->save();

        $this->withToken($plain)
            ->getJson('/api/nodes/revision')
            ->assertStatus(426);
    }

    public function test_heartbeat_ok_when_version_in_body(): void
    {
        $node = new RediNode();
        $node->name = 'n1';
        $node->public_ip = '1.2.3.4';
        $node->enabled = true;
        $node->save();
        $plain = $node->issueApiTokenPlain();
        $node->save();

        $this->withToken($plain)
            ->postJson('/api/nodes/heartbeat', ['version' => '0.1.2'])
            ->assertOk();
    }
}
