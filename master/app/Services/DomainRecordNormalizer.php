<?php

namespace App\Services;

class DomainRecordNormalizer
{
    public static function normalizeOriginalUrl(?string $url): string
    {
        $url = trim((string) $url);
        $url = preg_replace('#^https?://#i', '', $url);

        return rtrim($url, '/');
    }

    public static function normalizeSkipUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://'.$url;
        }

        if (! self::isAllowedRedirectUrl($url)) {
            return '';
        }

        return $url;
    }

    public static function isAllowedRedirectUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
