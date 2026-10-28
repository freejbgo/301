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

    public function test_wait_accepts_legacy_scheme_in_sync_domain_name(): void
    {
        $domain = 'legacy-sync.example';
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
            'domain_name' => 'https://'.$domain.'/',
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
        $this->expectExceptionMessage('Invalid hostname');

        app(Http01PropagationWaiter::class)->wait('not a host', 'tok', 'body', 0);
    }

    public function test_wait_rejects_invalid_token(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('token 无效');

        app(Http01PropagationWaiter::class)->wait('valid.example', '../evil', 'body', 0);
    }

    public function test_wait_rejects_empty_response_body(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('响应体无效');

        app(Http01PropagationWaiter::class)->wait('valid.example', 'tok', '   ', 0);
    }

    public function test_wait_rejects_control_chars_in_response_body(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('响应体无效');

        app(Http01PropagationWaiter::class)->wait('valid.example', 'tok', "bo\r\ndy", 0);
    }

    public function test_wait_does_not_accept_probe_with_trailing_newline(): void
    {
        $domain = 'probe-newline.example';
        $token = 'ok-token';
        $body = 'challenge-body';

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
            'http://'.$domain.'/.well-known/acme-challenge/'.$token => Http::response($body."\n", 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP-01 未在');

        app(Http01PropagationWaiter::class)->wait($domain, $token, $body, 0);
    }

    public function test_wait_rejects_trailing_newline_in_response_body(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('响应体无效');

        app(Http01PropagationWaiter::class)->wait('valid.example', 'ok-token', "body\n", 0);
    }

    public function test_timeout_message_mentions_ipv6_when_pool_aaaa_published(): void
    {
        config(['redi.publish_pool_aaaa' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pool AAAA');

        app(Http01PropagationWaiter::class)->wait('no-sync.example', 'tok', 'body', 0);
    }

    public function test_wait_does_not_follow_http_redirect_on_probe(): void
    {
        $domain = 'redirect-probe.example';
        $token = 'probe-token';
        $body = 'challenge-body';

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
            'http://'.$domain.'/.well-known/acme-challenge/'.$token => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/evil',
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP-01 未在');

        app(Http01PropagationWaiter::class)->wait($domain, $token, $body, 0);
    }
}
