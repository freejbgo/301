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
            'redi.ops_webhook_url' => 'https://hooks.example/ops',
            'redi.health_webhook_url' => '',
        ]);

        Http::fake(['https://hooks.example/*' => Http::response(['ok' => true], 200)]);

        $this->assertTrue(RediOpsWebhook::notifyTaskFailure('redi:renew-certificates', 'boom'));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://hooks.example/ops'
                && ($data['source'] ?? '') === 'redi-cron'
                && ($data['task'] ?? '') === 'redi:renew-certificates';
        });
    }

    public function test_falls_back_to_health_webhook_url(): void
    {
        config([
            'redi.ops_webhook_url' => '',
            'redi.health_webhook_url' => 'https://hooks.example/health',
        ]);

        Http::fake(['https://hooks.example/*' => Http::response([], 200)]);

        $this->assertTrue(RediOpsWebhook::notifyTaskFailure('redi:health'));
    }

    public function test_notify_task_failure_strips_control_chars_from_message(): void
    {
        config(['redi.ops_webhook_url' => 'https://hooks.example/ops']);

        Http::fake(['https://hooks.example/*' => Http::response([], 200)]);

        RediOpsWebhook::notifyTaskFailure('redi:prune-acme-challenges', "fail\r\nline");

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['message'] ?? '') === 'failline';
        });
    }
}
