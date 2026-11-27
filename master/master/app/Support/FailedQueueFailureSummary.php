<?php

namespace App\Support;

/**
 * 首页展示用的失败队列摘要。只取异常首行，并去掉私钥块和源码路径。
 */
final class FailedQueueFailureSummary
{
    public static function fromException(string $exception): string
    {
        $text = preg_replace('/-----BEGIN [A-Z0-9 ]+-----.*?-----END [A-Z0-9 ]+-----/s', '', $exception) ?? $exception;
        $parts = preg_split("/\r\n|\n|\r/", trim($text));
        $line = is_array($parts) ? (string) ($parts[0] ?? '') : '';
        $line = preg_replace('/ in \/\\S+:\\d+$/', '', $line) ?? $line;

        return OpsDisplaySanitizer::field($line, '—', 240);
    }

    public static function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $name = is_array($decoded) ? (string) ($decoded['displayName'] ?? '') : '';
        if ($name !== '' && str_contains($name, '\\')) {
            $name = substr($name, strrpos($name, '\\') + 1);
        }

        return OpsDisplaySanitizer::field($name, '—', 80);
    }
}
