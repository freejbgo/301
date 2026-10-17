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

    /** 规范化后的可签发/可启用主机名；无效则返回空字符串。 */
    public static function originalUrlIfValid(?string $url): string
    {
        $normalized = self::normalizeOriginalUrl($url);
        if ($normalized === '' || ! HostNameValidator::isValidHostLabel($normalized)) {
            return '';
        }

        return $normalized;
    }

    public static function isValidOriginalHost(?string $url): bool
    {
        return self::originalUrlIfValid($url) !== '';
    }

    /** 证书行 domain_name 是否与启用域名的规范化 original_url 一致（防漂移误下发/误续期）。 */
    public static function certMatchesAdminDomain(\App\Models\RediDomainCert $cert): bool
    {
        $canonical = self::originalUrlIfValid((string) optional($cert->domain)->original_url ?? '');
        if ($canonical === '') {
            return false;
        }
        $certHost = HostNameValidator::toCanonicalAscii((string) $cert->domain_name);

        return $certHost !== '' && $certHost === $canonical;
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
