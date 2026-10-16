<?php

namespace App\Services;

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

        return RediOpsWebhook::post([
            'source' => 'redi-health',
            'ok' => false,
            'checked_at' => now()->toIso8601String(),
            'issues' => $issues,
            'text' => self::formatPlainText($issues),
        ], RediOpsWebhook::healthWebhookUrl());
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
