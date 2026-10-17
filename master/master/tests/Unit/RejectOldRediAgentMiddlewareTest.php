<?php

namespace Tests\Unit;

use App\Http\Middleware\RejectOldRediAgent;
use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RejectOldRediAgentMiddlewareTest extends TestCase
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
            $table->boolean('enabled')->default(true);
            $table->string('api_token_hash')->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->timestamps();
        });
    }

    public function test_rejects_body_version_with_control_chars_without_node_fallback(): void
    {
        $node = new RediNode();
        $node->enabled = true;
        $node->agent_version = '0.1.3';
        $node->save();

        $request = Request::create('/api/nodes/heartbeat', 'POST', ['version' => "0.1.3\n"]);
        $request->attributes->set('redi_node', $node);

        $middleware = new RejectOldRediAgent();
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(426, $response->getStatusCode());
    }

    public function test_uses_stored_version_when_body_omits_version_key(): void
    {
        $node = new RediNode();
        $node->enabled = true;
        $node->agent_version = '0.1.3';
        $node->save();

        $request = Request::create('/api/nodes/revision', 'GET');
        $request->attributes->set('redi_node', $node);

        $middleware = new RejectOldRediAgent();
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }
}
