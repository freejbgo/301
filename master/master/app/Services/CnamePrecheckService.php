<?php

namespace App\Services;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Support\HostNameValidator;
use Illuminate\Support\Facades\Cache;

class CnamePrecheckService
{
    public static function pointsToEdgeCached(string $customerDomain, string $edgeFqdn, int $seconds = 60): bool
    {
        if (! HostNameValidator::isNodePublishableHost($customerDomain)
            || ! HostNameValidator::isNodePublishableHost($edgeFqdn)) {
            return false;
        }
        $customerDomain = HostNameValidator::toCanonicalAscii($customerDomain);
        $edgeFqdn = HostNameValidator::toCanonicalAscii($edgeFqdn);
        $key = 'cname-precheck:'.md5($customerDomain.'|'.$edgeFqdn);

        return (bool) Cache::remember($key, $seconds, function () use ($customerDomain, $edgeFqdn) {
            return self::pointsToEdge($customerDomain, $edgeFqdn);
        });
    }

    /**
     * 检查客户域名是否 CNAME（含链式）到租户 edge 或 pool 主机名。
     */
    public static function pointsToEdge(string $customerDomain, string $edgeFqdn): bool
    {
        if (! HostNameValidator::isNodePublishableHost($customerDomain)
            || ! HostNameValidator::isNodePublishableHost($edgeFqdn)) {
            return false;
        }

        $customerDomain = HostNameValidator::toCanonicalAscii($customerDomain);
        $edgeFqdn = HostNameValidator::toCanonicalAscii($edgeFqdn);

        if (! function_exists('dns_get_record')) {
            return false;
        }

        $poolFqdn = RediPlatformDns::instance()->poolFqdn();
        $allowedTargets = [];
        foreach ([$edgeFqdn, $poolFqdn] as $target) {
            $target = HostNameValidator::toCanonicalAscii((string) $target);
            if (HostNameValidator::isNodePublishableHost((string) $target)) {
                $allowedTargets[] = $target;
            }
        }
        $allowedTargets = array_values(array_unique($allowedTargets));

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

        if (filter_var(config('redi.dns_precheck_allow_a_to_pool', true), FILTER_VALIDATE_BOOLEAN)) {
            return self::aRecordPointsToPool($customerDomain);
        }

        return false;
    }

    public static function aRecordPointsToPool(string $customerDomain): bool
    {
        if (! HostNameValidator::isNodePublishableHost($customerDomain)) {
            return false;
        }
        $customerDomain = HostNameValidator::toCanonicalAscii($customerDomain);

        $poolIps = RediNode::enabledPublicIps();
        if ($poolIps === []) {
            return false;
        }
        $flip = array_flip($poolIps);
        foreach (self::resolveA($customerDomain) as $ip) {
            if (isset($flip[$ip])) {
                return true;
            }
        }

        return false;
    }

    protected static function cnameChainReaches(string $host, array $targets, int $depth): bool
    {
        if ($depth <= 0) {
            return false;
        }
        if (! HostNameValidator::isNodePublishableHost($host)) {
            return false;
        }
        $host = HostNameValidator::toCanonicalAscii($host);
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
        if (! HostNameValidator::isNodePublishableHost($host)) {
            return [];
        }
        $host = HostNameValidator::toCanonicalAscii($host);
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
