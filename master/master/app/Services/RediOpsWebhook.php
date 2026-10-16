<?php

namespace App\Services;

use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RediOpsWebhook
{
    public static function healthWebhookUrl(): string
    {
        return trim((string) config('redi.health_webhook_url', ''));
    }

    /** Cron 失败等；未配置 ops 时回退 health URL。 */
    public static function webhookUrl(): string
    {
        $ops = trim((string) config('redi.ops_webhook_url', ''));
        if ($ops !== '') {
            return $ops;
        }

        return self::healthWebhookUrl();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function post(array $payload, ?string $url = null): bool
    {
        $url = $url ?? self::webhookUrl();
        if ($url === '') {
            return false;
        }

        if (isset($payload['message'])) {
            $payload['message'] = OpsDisplaySanitizer::stripControlChars((string) $payload['message']);
        }
        if (isset($payload['text'])) {
            $payload['text'] = OpsDisplaySanitizer::stripControlChars((string) $payload['text']);
        }
        if (! isset($payload['text']) && isset($payload['message'])) {
            $payload['text'] = (string) $payload['message'];
        }
        if (isset($payload['issues']) && is_array($payload['issues'])) {
            foreach ($payload['issues'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (isset($row['message'])) {
                    $row['message'] = OpsDisplaySanitizer::stripControlChars((string) $row['message']);
                }
                $payload['issues'][$i] = $row;
            }
        }

        $logLabel = ($url === self::healthWebhookUrl()) ? 'redi health webhook' : 'redi ops webhook';

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning($logLabel.' failed', [
                    'status' => $response->status(),
                    'body' => OpsDisplaySanitizer::logSnippet($response->body()),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning($logLabel.' error: '.OpsDisplaySanitizer::logSnippet($e->getMessage(), 300));

            return false;
        }
    }

    public static function notifyTaskFailure(string $task, string $message = ''): bool
    {
        $task = mb_substr(trim($task), 0, 120);
        if ($task === '') {
            $task = 'redi:ops';
        }
        $message = OpsDisplaySanitizer::stripControlChars(trim($message));
        $message = $message !== '' ? $message : "定时任务失败：{$task}";
        $message = mb_substr($message, 0, 4000);

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
