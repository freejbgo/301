<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RediHealthNotifier
{
    /**
     * @param  array<int, array{level: string, code: string, message: string}>  $issues
     */
    public static function notifyWebhook(array $issues): bool
    {
        $url = trim((string) config('redi.health_webhook_url', ''));
        if ($url === '' || $issues === []) {
            return false;
        }

        $payload = [
            'source' => 'redi-health',
            'ok' => false,
            'checked_at' => now()->toIso8601String(),
            'issues' => $issues,
            'text' => self::formatPlainText($issues),
        ];

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('redi:health webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('redi:health webhook error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param  array<int, array{level: string, code: string, message: string}>  $issues
     */
    public static function formatPlainText(array $issues): string
    {
        $lines = ['[301] redi:health 发现 '.count($issues).' 项待处理：'];
        foreach ($issues as $row) {
            $tag = $row['level'] === 'critical' ? '严重' : '注意';
            $lines[] = '['.$tag.'] '.$row['message'];
        }

        return implode("\n", $lines);
    }
}
