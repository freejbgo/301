<?php

namespace App\Support;

/**
 * 运营输出（CLI / 日志 / webhook）用的单行展示字段，避免控制字符与过长文本。
 */
final class OpsDisplaySanitizer
{
    public static function field(?string $value, string $fallback = '—', int $maxLen = 120): string
    {
        $text = str_replace(["\r", "\n", "\0", "\t"], '', (string) $value);
        $text = trim($text);
        if ($text === '') {
            return $fallback;
        }

        return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen).'…' : $text;
    }

    public static function stripControlChars(string $value): string
    {
        return str_replace(["\r", "\n", "\0", "\t"], '', $value);
    }

    public static function logSnippet(?string $value, int $maxLen = 500): string
    {
        $text = trim(self::stripControlChars((string) $value));
        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen).'…' : $text;
    }

    /**
     * @param  array<int, array{level: string, code: string, message: string}>  $issues
     * @return array<int, array{level: string, code: string, message: string}>
     */
    public static function sanitizeHealthIssues(array $issues): array
    {
        foreach ($issues as $i => $row) {
            if (isset($row['message'])) {
                $row['message'] = self::stripControlChars((string) $row['message']);
            }
            $issues[$i] = $row;
        }

        return $issues;
    }
}
