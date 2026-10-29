<?php

namespace Tests\Unit;

use App\Services\RediOpsWebhook;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RediOpsWebhookTest extends TestCase
{
    public function test_notify_task_failure_uses_ops_url(): void
    {
        config([
            'redi.ops_webhook_url' => 'https://example.com/ops',
            'redi.health_webhook_url' => '',
        ]);

        Http::fake(fn () => Http::response(['ok' => true], 200));

        $this->assertTrue(RediOpsWebhook::notifyTaskFailure('redi:renew-certificates', 'boom'));

        Http::assertSent(function ($request) {
            $data = $request->data();
            $hostHeader = $request->header('Host')[0] ?? '';

            return $hostHeader === 'example.com'
                && str_contains($request->url(), '/ops')
                && ($data['source'] ?? '') === 'redi-cron'
                && ($data['task'] ?? '') === 'redi:renew-certificates';
        });
    }

    public function test_falls_back_to_health_webhook_url(): void
    {
        config([
            'redi.ops_webhook_url' => '',
            'redi.health_webhook_url' => 'https://example.com/health',
        ]);

        Http::fake(fn () => Http::response([], 200));

        $this->assertTrue(RediOpsWebhook::notifyTaskFailure('redi:health'));
    }

    public function test_notify_task_failure_strips_control_chars_from_task(): void
    {
        config(['redi.ops_webhook_url' => 'https://example.com/ops']);
        Http::fake(fn () => Http::response([], 200));

        RediOpsWebhook::notifyTaskFailure("redi:renew\r\ncertificates", 'x');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['task'] ?? '') === 'redi:renewcertificates';
        });
    }

    public function test_notify_task_failure_strips_control_chars_from_message(): void
    {
        config(['redi.ops_webhook_url' => 'https://example.com/ops']);

        Http::fake(fn () => Http::response([], 200));

        RediOpsWebhook::notifyTaskFailure('redi:prune-acme-challenges', "fail\r\nline");

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['message'] ?? '') === 'failline';
        });
    }

    public function test_webhook_url_rejects_control_chars_in_config(): void
    {
        config([
            'redi.ops_webhook_url' => "https://example.com/ops\n",
            'redi.health_webhook_url' => '',
        ]);

        Http::fake();

        $this->assertFalse(RediOpsWebhook::notifyTaskFailure('redi:health', 'x'));

        Http::assertNothingSent();
    }

    public function test_post_rejects_explicit_url_with_control_chars(): void
    {
        Http::fake();

        $this->assertFalse(RediOpsWebhook::post(['ok' => true], "https://example.com/h\n"));

        Http::assertNothingSent();
    }

    public function test_webhook_url_rejects_private_ip_targets(): void
    {
        config([
            'redi.ops_webhook_url' => 'http://127.0.0.1/hook',
            'redi.health_webhook_url' => '',
        ]);

        Http::fake();

        $this->assertFalse(RediOpsWebhook::notifyTaskFailure('redi:health', 'x'));

        Http::assertNothingSent();
    }

    public function test_post_sanitizes_issue_messages_in_payload(): void
    {
        config(['redi.health_webhook_url' => 'https://example.com/health']);
        Http::fake(fn () => Http::response([], 200));

        RediOpsWebhook::post([
            'issues' => [
                ['level' => 'warning', 'code' => 'x', 'message' => "m\r\nsg"],
            ],
        ], 'https://example.com/health');

        Http::assertSent(function ($request) {
            $issues = $request->data()['issues'] ?? [];

            return ($issues[0]['message'] ?? '') === 'msg';
        });
    }
}
