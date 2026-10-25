<?php

namespace App\Services;

use App\Support\OpsDisplaySanitizer;
use App\Support\RediErrorMessage;

class RediHealthNotifier
{
    /**
     * @param  array<int, array{level: string, code: string, message: string}>  $issues
     */
    public static function notifyWebhook(array $issues): bool
    {
        if ($issues === []) {
            return false;
        }

        $total = count($issues);
        $payload = $total > 40 ? array_slice($issues, 0, 40) : $issues;

        return RediOpsWebhook::post([
            'source' => 'redi-health',
            'ok' => false,
            'checked_at' => now()->toIso8601String(),
            'issues' => $payload,
            'issues_total' => $total,
            'issues_truncated' => $total > 40,
            'text' => self::formatPlainText($payload, $total),
        ], RediOpsWebhook::healthWebhookUrl());
    }

    /**
     * @param  array<int, array{level: string, code: string, message: string}>  $issues
     */
    public static function formatPlainText(array $issues, ?int $totalCount = null): string
    {
        $total = $totalCount ?? count($issues);
        $lines = [RediErrorMessage::get('health.summary', ['count' => $total])];
        if ($total > count($issues)) {
            $lines[] = RediErrorMessage::get('health.truncated', ['shown' => count($issues)]);
        }
        foreach ($issues as $row) {
            $tag = $row['level'] === 'critical'
                ? RediErrorMessage::get('health.critical')
                : RediErrorMessage::get('health.warning');
            $lines[] = '['.$tag.'] '.OpsDisplaySanitizer::field($row['message'] ?? '', '—', 500);
        }

        $text = implode("\n", $lines);
        if (strlen($text) > 4000) {
            return substr($text, 0, 3997).'...';
        }

        return $text;
    }
}
