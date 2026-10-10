<?php

namespace App\Services;

class CnamePrecheckService
{
    /**
     * 检查客户域名是否 CNAME 到租户 edge（或解析链最终指向 pool 的 A）。
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

        $records = @dns_get_record($customerDomain, DNS_CNAME);
        if (is_array($records)) {
            foreach ($records as $row) {
                $target = strtolower(rtrim($row['target'] ?? '', '.'));
                if ($target === $edgeFqdn) {
                    return true;
                }
            }
        }

        return false;
    }
}
