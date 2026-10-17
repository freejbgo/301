<?php

namespace App\Services;

use App\Support\OpsDisplaySanitizer;

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
        $lines = ['[301] redi:health 发现 '.$total.' 项待处理：'];
        if ($total > count($issues)) {
            $lines[] = '[注意] 以下仅展示前 '.count($issues).' 条（完整列表见 --json）';
        }
        foreach ($issues as $row) {
            $tag = $row['level'] === 'critical' ? '严重' : '注意';
            $lines[] = '['.$tag.'] '.OpsDisplaySanitizer::field($row['message'] ?? '', '—', 500);
        }

        $text = implode("\n", $lines);
        if (strlen($text) > 4000) {
            return substr($text, 0, 3997).'...';
        }

        return $text;
    }
}
