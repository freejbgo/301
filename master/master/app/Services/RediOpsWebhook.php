<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RediOpsWebhook
{
    public static function webhookUrl(): string
    {
        $ops = trim((string) config('redi.ops_webhook_url', ''));
        if ($ops !== '') {
            return $ops;
        }

        return trim((string) config('redi.health_webhook_url', ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function post(array $payload): bool
    {
        $url = self::webhookUrl();
        if ($url === '') {
            return false;
        }

        if (! isset($payload['text']) && isset($payload['message'])) {
            $payload['text'] = (string) $payload['message'];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('redi ops webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('redi ops webhook error: '.$e->getMessage());

            return false;
        }
    }

    public static function notifyTaskFailure(string $task, string $message = ''): bool
    {
        $message = trim($message) !== '' ? trim($message) : "定时任务失败：{$task}";

        return self::post([
            'source' => 'redi-cron',
            'ok' => false,
            'task' => $task,
            'message' => $message,
            'text' => '[301] '.$message,
            'at' => now()->toIso8601String(),
        ]);
    }
}
