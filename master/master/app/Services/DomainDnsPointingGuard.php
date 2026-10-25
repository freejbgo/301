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
        $originalUrl = DomainRecordNormalizer::originalUrlIfValid($originalUrl);
        if ($originalUrl === '') {
            return false;
        }

        TenantEdgeService::ensureForAdmin($adminId);
        $edgeFqdn = TenantEdgeService::cnameTargetForAdmin($adminId);
        if ($edgeFqdn === '' || TenantEdgeService::isPlaceholderCnameTarget($edgeFqdn)) {
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
            'status' => __('redi-admin-domain.validation.dns_enable_status', ['edge' => $edgeFqdn]),
            'original_url' => __('redi-admin-domain.validation.dns_enable_url'),
        ]);
    }
}
