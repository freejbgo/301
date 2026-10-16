<?php

namespace Tests\Unit;

use App\Services\Acme\Http01PropagationWaiter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Http01PropagationWaiterTest extends TestCase
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
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_wait_succeeds_when_node_synced_and_http_probe_matches(): void
    {
        $domain = 'acme-prep.example';
        $token = 'test-token';
        $body = 'challenge-response-body';

        \DB::table('redi_nodes')->insert([
            'enabled' => true,
            'last_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_acme_challenge_node_sync')->insert([
            'node_id' => 1,
            'domain_name' => $domain,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'http://'.$domain.'/.well-known/acme-challenge/'.$token => Http::response($body, 200),
        ]);

        app(Http01PropagationWaiter::class)->wait($domain, $token, $body, 15);

        Http::assertSent(function ($request) use ($domain, $token) {
            return $request->url() === 'http://'.$domain.'/.well-known/acme-challenge/'.$token;
        });
    }

    public function test_wait_throws_when_no_node_synced_before_deadline(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP-01 未在');

        app(Http01PropagationWaiter::class)->wait('no-sync.example', 'tok', 'body', 0);
    }

    public function test_wait_rejects_invalid_domain(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('域名无效');

        app(Http01PropagationWaiter::class)->wait('not a host', 'tok', 'body', 0);
    }

    public function test_wait_rejects_invalid_token(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('token 无效');

        app(Http01PropagationWaiter::class)->wait('valid.example', '../evil', 'body', 0);
    }
}
