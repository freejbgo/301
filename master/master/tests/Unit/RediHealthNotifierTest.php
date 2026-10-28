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
            'redi.health_webhook_url' => 'https://example.com/health',
            'redi.ops_webhook_url' => 'https://example.com/ops',
        ]);

        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertTrue(RediHealthNotifier::notifyWebhook([
            ['level' => 'warning', 'code' => 'test', 'message' => 'x'],
        ]));

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/health');
    }

    public function test_notify_truncates_large_issue_list(): void
    {
        config(['redi.health_webhook_url' => 'https://example.com/health']);

        Http::fake(['https://example.com/*' => Http::response([], 200)]);

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
            'redi.health_webhook_url' => 'https://example.com/health',
            'redi.ops_webhook_url' => '',
        ]);

        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $issues = [
            ['level' => 'critical', 'code' => 'cert_expired', 'message' => '证书已过期：a.com'],
        ];

        $this->assertTrue(RediHealthNotifier::notifyWebhook($issues));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://example.com/health'
                && ($data['source'] ?? '') === 'redi-health'
                && ($data['ok'] ?? true) === false
                && str_contains((string) ($data['issues'][0]['message'] ?? ''), 'a.com');
        });
    }

    public function test_format_plain_text_strips_control_chars(): void
    {
        $text = RediHealthNotifier::formatPlainText([
            ['level' => 'warning', 'code' => 'test', 'message' => "bad\r\nmsg"],
        ]);

        $this->assertStringContainsString('badmsg', $text);
        $this->assertStringNotContainsString("\r\n", $text);
    }
}
