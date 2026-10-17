<?php

namespace App\Services;

use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;

class DomainRecordNormalizer
{
    public static function normalizeOriginalUrl(?string $url): string
    {
        $raw = (string) $url;
        if ($raw === '' || OpsDisplaySanitizer::containsControlChars($raw)) {
            return '';
        }
        $url = trim($raw);
        $url = preg_replace('#^https?://#i', '', $url);
        $url = strtolower(rtrim($url, '/'));
        if (str_contains($url, '/') || str_contains($url, '?') || str_contains($url, '#')) {
            return '';
        }

        return HostNameValidator::toCanonicalAscii($url);
    }

    public static function isValidOriginalHost(?string $url): bool
    {
        $normalized = self::normalizeOriginalUrl($url);

        return $normalized !== '' && HostNameValidator::isValidHostLabel($normalized);
    }

    public static function originalUrlHash(string $normalizedOriginalUrl): string
    {
        $normalizedOriginalUrl = self::normalizeOriginalUrl($normalizedOriginalUrl);
        if ($normalizedOriginalUrl === '') {
            return '';
        }

        return hash('sha256', $normalizedOriginalUrl);
    }

    public static function normalizeSkipUrl(?string $url): string
    {
        $raw = (string) $url;
        if ($raw === '' || OpsDisplaySanitizer::containsControlChars($raw)) {
            return '';
        }
        $url = trim($raw);
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

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
