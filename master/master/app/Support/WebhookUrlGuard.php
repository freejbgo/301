<?php

namespace App\Support;

class WebhookUrlGuard
{
    /** @var list<string> */
    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
    ];

    /** @var list<string> */
    private const BLOCKED_HOST_EXACT = [
        'localhost',
        'metadata.google.internal',
        'metadata',
    ];

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

        if (! self::isAllowedHostname($host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return self::hostResolvesOnlyToPublicIps($host);
    }

    protected static function isAllowedHostname(string $host): bool
    {
        if (in_array($host, self::BLOCKED_HOST_EXACT, true)) {
            return false;
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    protected static function hostResolvesOnlyToPublicIps(string $host): bool
    {
        if (! function_exists('dns_get_record')) {
            return false;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return false;
        }

        foreach ($records as $rec) {
            $ip = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve hostname to public IPs for outbound webhook pinning (mitigate DNS rebinding).
     *
     * @return list<string>
     */
    public static function resolvePublicIpsForHost(string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '' || ! self::isAllowedHostname($host)) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return [];
            }

            return [$host];
        }

        if (! function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $ips = [];
        foreach ($records as $rec) {
            $ip = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return [];
            }
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return array{host: string, scheme: string, port: int, path: string, query: string, ips: list<string>}|null
     */
    public static function pinnedWebhookTarget(string $url): ?array
    {
        if (! self::isSafeOutboundWebhookUrl($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        $ips = self::resolvePublicIpsForHost($host);
        if ($ips === []) {
            return null;
        }

        return [
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
            'path' => $path,
            'query' => $query,
            'ips' => $ips,
        ];
    }
}
