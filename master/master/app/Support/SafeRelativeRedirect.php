<?php

namespace App\Support;

class SafeRelativeRedirect
{
    /** 仅允许站内相对路径（拒绝 // 协议相对外链）。 */
    public static function isSafe(?string $path): bool
    {
        return self::normalizeForRedirect($path) !== null;
    }

    /**
     * 将 admin_url() 等同域绝对 URL 规范为相对路径；非法或外链返回 null。
     */
    public static function normalizeForRedirect(?string $target): ?string
    {
        if (! is_string($target) || $target === '') {
            return null;
        }
        if (self::containsUnsafeChars($target)) {
            return null;
        }

        $path = $target;
        if (preg_match('#^https?://#i', $target)) {
            $parsed = parse_url($target);
            if ($parsed === false || ! isset($parsed['host'])) {
                return null;
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                return null;
            }
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (! is_string($appHost) || $appHost === '') {
                return null;
            }
            if (strcasecmp((string) $parsed['host'], $appHost) !== 0) {
                return null;
            }
            $path = (string) ($parsed['path'] ?? '');
            if (isset($parsed['query']) && $parsed['query'] !== '') {
                $path .= '?'.$parsed['query'];
            }
        }

        if (! self::isSafeRelativePath($path)) {
            return null;
        }

        return self::normalizeSafeRelativePath($path);
    }

    private static function containsUnsafeChars(string $value): bool
    {
        return str_contains($value, "\r")
            || str_contains($value, "\n")
            || str_contains($value, "\0")
            || str_contains($value, "\t");
    }

    private static function isSafeRelativePath(string $path): bool
    {
        $normalized = self::normalizeSafeRelativePath($path);

        return $normalized !== null;
    }

    private static function normalizeSafeRelativePath(string $path): ?string
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            return null;
        }
        if (str_contains($path, '\\')) {
            return null;
        }
        $decoded = rawurldecode($path);
        if ($decoded === '' || ! str_starts_with($decoded, '/')
            || str_starts_with($decoded, '//') || str_contains($decoded, '\\')) {
            return null;
        }
        if (str_starts_with($path, '//')) {
            return null;
        }

        return $path;
    }
}
