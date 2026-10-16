<?php

namespace Tests\Unit;

use App\Services\RediHealthNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RediHealthNotifierTest extends TestCase
{
    public function test_notify_skips_when_url_empty(): void
    {
        config(['redi.health_webhook_url' => '']);

        $this->assertFalse(RediHealthNotifier::notifyWebhook([
            ['level' => 'warning', 'code' => 'test', 'message' => 'x'],
        ]));
    }

    public function test_notify_uses_health_url_when_ops_url_also_set(): void
    {
        config([
            'redi.health_webhook_url' => 'https://hooks.example/health',
            'redi.ops_webhook_url' => 'https://hooks.example/ops',
        ]);

        Http::fake([
            'https://hooks.example/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertTrue(RediHealthNotifier::notifyWebhook([
            ['level' => 'warning', 'code' => 'test', 'message' => 'x'],
        ]));

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example/health');
    }

    public function test_notify_posts_json_payload(): void
    {
        config([
            'redi.health_webhook_url' => 'https://hooks.example/health',
            'redi.ops_webhook_url' => '',
        ]);

        Http::fake([
            'https://hooks.example/*' => Http::response(['ok' => true], 200),
        ]);

        $issues = [
            ['level' => 'critical', 'code' => 'cert_expired', 'message' => '证书已过期：a.com'],
        ];

        $this->assertTrue(RediHealthNotifier::notifyWebhook($issues));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://hooks.example/health'
                && ($data['source'] ?? '') === 'redi-health'
                && ($data['ok'] ?? true) === false
                && str_contains((string) ($data['text'] ?? ''), 'a.com');
        });
    }
}
