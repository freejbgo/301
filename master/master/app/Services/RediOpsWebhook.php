<?php

namespace App\Services;

use App\Support\OpsDisplaySanitizer;
use App\Support\RediErrorMessage;
use App\Support\WebhookUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RediOpsWebhook
{
    public static function healthWebhookUrl(): string
    {
        return self::configWebhookUrl('health_webhook_url');
    }

    /** Cron 失败等；未配置 ops 时回退 health URL。 */
    public static function webhookUrl(): string
    {
        $ops = self::configWebhookUrl('ops_webhook_url');
        if ($ops !== '') {
            return $ops;
        }

        return self::healthWebhookUrl();
    }

    protected static function configWebhookUrl(string $configKey): string
    {
        return self::normalizeWebhookUrl((string) config('redi.'.$configKey, ''));
    }

    public static function normalizeWebhookUrl(string $raw): string
    {
        if ($raw === '' || $raw !== trim($raw) || OpsDisplaySanitizer::containsControlChars($raw)) {
            return '';
        }

        $url = trim($raw);
        if (! WebhookUrlGuard::isSafeOutboundWebhookUrl($url)) {
            return '';
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function post(array $payload, ?string $url = null): bool
    {
        $url = $url !== null ? self::normalizeWebhookUrl($url) : self::webhookUrl();
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

        $pin = WebhookUrlGuard::pinnedWebhookTarget($url);
        if ($pin === null) {
            Log::warning($logLabel.' rejected: could not pin public IP for URL');

            return false;
        }

        try {
            foreach ($pin['ips'] as $ip) {
                $resolveEntry = self::curlResolveEntry($pin['host'], $pin['port'], $ip);
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->withOptions([
                        'allow_redirects' => false,
                        'curl' => [
                            CURLOPT_RESOLVE => [$resolveEntry],
                        ],
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    return true;
                }

                Log::warning($logLabel.' failed', [
                    'status' => $response->status(),
                    'body' => OpsDisplaySanitizer::logSnippet($response->body()),
                    'pinned_ip' => OpsDisplaySanitizer::field($ip, '', 64),
                ]);
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning($logLabel.' error: '.OpsDisplaySanitizer::logSnippet($e->getMessage(), 300));

            return false;
        }
    }

    protected static function curlResolveEntry(string $host, int $port, string $ip): string
    {
        return $host.':'.$port.':'.$ip;
    }

    public static function notifyTaskFailure(string $task, string $message = ''): bool
    {
        $task = OpsDisplaySanitizer::field($task, 'redi:ops', 120);
        $message = OpsDisplaySanitizer::field($message, '', 4000);
        if ($message === '' || $message === '—') {
            $message = RediErrorMessage::get('ops.cron_failed', ['task' => $task]);
        }

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
