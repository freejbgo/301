<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpsWebhookNotifyCommandTest extends TestCase
{
    public function test_ops_webhook_fails_when_url_not_configured(): void
    {
        config([
            'redi.ops_webhook_url' => '',
            'redi.health_webhook_url' => '',
        ]);

        $exit = Artisan::call('redi:ops-webhook', ['--task' => 'redi:renew-certificates']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Webhook 未配置', Artisan::output());
    }

    public function test_ops_webhook_sends_to_ops_url(): void
    {
        config([
            'redi.ops_webhook_url' => 'https://hooks.example/ops',
            'redi.health_webhook_url' => 'https://hooks.example/health',
        ]);

        Http::fake(['https://hooks.example/*' => Http::response([], 200)]);

        $exit = Artisan::call('redi:ops-webhook', [
            '--task' => 'redi:prune-acme-challenges',
            '--message' => 'cron failed',
        ]);
        $this->assertSame(0, $exit);

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example/ops'
            && ($request->data()['task'] ?? '') === 'redi:prune-acme-challenges');
    }
}
