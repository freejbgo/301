<?php

namespace App\Support;

class HostNameValidator
{
    /**
     * 边缘 / ACME 用的主机名（与 original_url 规范化结果一致：无 scheme、无路径）。
     */
    public static function isValidHostLabel(string $host): bool
    {
        if (OpsDisplaySanitizer::containsControlChars($host)) {
            return false;
        }
        $host = strtolower(trim($host, '.'));
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (function_exists('idn_to_ascii')) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, $variant);
            if ($ascii === false) {
                return false;
            }
            $host = strtolower($ascii);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
        }

        return true;
    }

    /**
     * 入库用的 ASCII 主机名（IDN → punycode）。
     */
    public static function toCanonicalAscii(string $host): string
    {
        if (OpsDisplaySanitizer::containsControlChars($host)) {
            return '';
        }
        $host = strtolower(trim($host, '.'));
        if ($host === '') {
            return '';
        }
        if (function_exists('idn_to_ascii')) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, $variant);
            if ($ascii !== false) {
                return strtolower($ascii);
            }
        }

        return $host;
    }

    /** 节点 API / 部署 / revision 可使用的原始主机名（含 IDN 与控制字符拒绝）。 */
    public static function isNodePublishableHost(string $raw): bool
    {
        if (OpsDisplaySanitizer::containsControlChars($raw)) {
            return false;
        }
        $host = trim($raw);
        $host = preg_replace('#^https?://#i', '', $host);
        $host = strtolower(rtrim($host, '/'));
        if ($host === '' || str_contains($host, '/') || str_contains($host, '?') || str_contains($host, '#')) {
            return false;
        }
        $host = self::toCanonicalAscii($host);

        return $host !== '' && self::isValidHostLabel($host);
    }
}
