<?php

namespace App\Services;

use App\Support\AdminTenantContext;
class DomainDnsPointingGuard
{
    public static function mustPointToEdgeBeforeEnable(): bool
    {
        return filter_var(config('redi.require_dns_pointing_to_enable', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function pointsToTenantEdge(int $adminId, string $originalUrl): bool
    {
        if (! DomainRecordNormalizer::isValidOriginalHost($originalUrl)) {
            return false;
        }
        $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($originalUrl);

        TenantEdgeService::ensureForAdmin($adminId);
        $edgeFqdn = TenantEdgeService::cnameTargetForAdmin($adminId);
        if ($edgeFqdn === '' || strncmp($edgeFqdn, '（', 3) === 0) {
            return false;
        }

        return CnamePrecheckService::pointsToEdge($originalUrl, $edgeFqdn);
    }

    public static function assertCanEnable(int $adminId, string $originalUrl): void
    {
        if (! self::mustPointToEdgeBeforeEnable()) {
            return;
        }
        if (AdminTenantContext::isPlatformAdministrator()) {
            return;
        }
        if (self::pointsToTenantEdge($adminId, $originalUrl)) {
            return;
        }

        $edgeFqdn = TenantEdgeService::cnameTargetForAdmin($adminId);

        throw \Illuminate\Validation\ValidationException::withMessages([
            'status' => '启用前须将域名 CNAME 到 '.$edgeFqdn.'（或直接 A 记录到平台节点公网 IP）。解析生效后再启用。',
            'original_url' => '启用前须将域名解析指向平台（CNAME 或 A 到节点池）',
        ]);
    }
}
