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

    public function test_notify_truncates_large_issue_list(): void
    {
        config(['redi.health_webhook_url' => 'https://hooks.example/health']);

        Http::fake(['https://hooks.example/*' => Http::response([], 200)]);

        $issues = [];
        for ($i = 0; $i < 45; $i++) {
            $issues[] = ['level' => 'warning', 'code' => 'test', 'message' => 'm'.$i];
        }

        $this->assertTrue(RediHealthNotifier::notifyWebhook($issues));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return count($data['issues'] ?? []) === 40
                && ($data['issues_total'] ?? 0) === 45
                && ($data['issues_truncated'] ?? false) === true;
        });
    }

    public function test_format_plain_text_truncates_very_long_output(): void
    {
        $issues = [];
        for ($i = 0; $i < 50; $i++) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'test',
                'message' => str_repeat('x', 200),
            ];
        }

        $text = RediHealthNotifier::formatPlainText($issues);

        $this->assertLessThanOrEqual(4000, strlen($text));
        $this->assertStringEndsWith('...', $text);
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
