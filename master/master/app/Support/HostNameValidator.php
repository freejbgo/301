<?php

namespace App\Support;

class HostNameValidator
{
    /**
     * 边缘 / ACME 用的主机名（与 original_url 规范化结果一致：无 scheme、无路径）。
     */
    public static function isValidHostLabel(string $host): bool
    {
        $host = strtolower(trim($host, '.'));
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
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
}
