<?php

namespace App\Services;

use App\Models\RediPlatformDns;
use Illuminate\Support\Facades\Cache;

class CnamePrecheckService
{
    public static function pointsToEdgeCached(string $customerDomain, string $edgeFqdn, int $seconds = 60): bool
    {
        $key = 'cname-precheck:'.md5(strtolower($customerDomain).'|'.strtolower($edgeFqdn));

        return (bool) Cache::remember($key, $seconds, function () use ($customerDomain, $edgeFqdn) {
            return self::pointsToEdge($customerDomain, $edgeFqdn);
        });
    }

    /**
     * 检查客户域名是否 CNAME（含链式）到租户 edge 或 pool 主机名。
     */
    public static function pointsToEdge(string $customerDomain, string $edgeFqdn): bool
    {
        $customerDomain = strtolower(trim($customerDomain, '.'));
        $edgeFqdn = strtolower(trim($edgeFqdn, '.'));

        if ($customerDomain === '' || $edgeFqdn === '') {
            return false;
        }

        if (! function_exists('dns_get_record')) {
            return false;
        }

        $poolFqdn = RediPlatformDns::instance()->poolFqdn();
        $allowedTargets = array_filter([$edgeFqdn, $poolFqdn]);

        $cnames = @dns_get_record($customerDomain, DNS_CNAME);
        if (is_array($cnames)) {
            foreach ($cnames as $row) {
                $target = strtolower(rtrim($row['target'] ?? '', '.'));
                if (in_array($target, $allowedTargets, true)) {
                    return true;
                }
                if (self::cnameChainReaches($target, $allowedTargets, 4)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected static function cnameChainReaches(string $host, array $targets, int $depth): bool
    {
        if ($depth <= 0) {
            return false;
        }
        $host = strtolower(rtrim($host, '.'));
        if (in_array($host, $targets, true)) {
            return true;
        }
        $rows = @dns_get_record($host, DNS_CNAME);
        if (! is_array($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            $next = strtolower(rtrim($row['target'] ?? '', '.'));
            if (in_array($next, $targets, true) || self::cnameChainReaches($next, $targets, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    protected static function resolveA(string $host): array
    {
        $host = strtolower(trim($host, '.'));
        if ($host === '') {
            return [];
        }
        $rows = @dns_get_record($host, DNS_A);
        if (! is_array($rows)) {
            return [];
        }
        $ips = [];
        foreach ($rows as $row) {
            if (! empty($row['ip'])) {
                $ips[] = $row['ip'];
            }
        }

        return array_values(array_unique($ips));
    }
}
