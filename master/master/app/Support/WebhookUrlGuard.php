<?php

namespace App\Support;

class WebhookUrlGuard
{
    /**
     * Reject webhook targets that would reach loopback, link-local, or RFC1918/reserved space.
     */
    public static function isSafeOutboundWebhookUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || OpsDisplaySanitizer::containsControlChars($url)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        return true;
    }
}
