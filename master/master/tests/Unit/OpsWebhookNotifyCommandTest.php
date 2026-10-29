<?php

namespace Tests\Unit;

use App\Support\RediErrorMessage;
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
        $this->assertStringContainsString(RediErrorMessage::get('cli.ops_webhook_failed'), Artisan::output());
    }

    public function test_ops_webhook_sends_to_ops_url(): void
    {
        config([
            'redi.ops_webhook_url' => 'https://example.com/ops',
            'redi.health_webhook_url' => 'https://example.com/health',
        ]);

        Http::fake(fn () => Http::response([], 200));

        $exit = Artisan::call('redi:ops-webhook', [
            '--task' => 'redi:prune-acme-challenges',
            '--message' => 'cron failed',
        ]);
        $this->assertSame(0, $exit);

        Http::assertSent(function ($request) {
            $hostHeader = $request->header('Host')[0] ?? '';

            return $hostHeader === 'example.com'
                && str_contains($request->url(), '/ops')
                && ($request->data()['task'] ?? '') === 'redi:prune-acme-challenges';
        });
    }
}
