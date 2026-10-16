<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;

class TenantOpsSummary
{
    /**
     * @return array{
     *     domains_enabled: int,
     *     domains_disabled: int,
     *     certs_active: int,
     *     certs_needs_attention: int,
     *     certs_expiring_soon: int
     * }
     */
    public static function forAdmin(int $adminId): array
    {
        $domainsEnabled = RediAdminDomain::query()
            ->where('admin_id', $adminId)
            ->where('status', 1)
            ->count();

        $domainsDisabled = RediAdminDomain::query()
            ->where('admin_id', $adminId)
            ->where('status', 2)
            ->count();

        $certQuery = RediDomainCert::query()
            ->whereHas('domain', fn ($q) => $q->where('admin_id', $adminId)->where('status', 1));

        $certsActive = (clone $certQuery)
            ->where('status', RediDomainCert::STATUS_ACTIVE)
            ->count();

        $certsNeedsAttention = (clone $certQuery)
            ->whereIn('status', [
                RediDomainCert::STATUS_PENDING_DNS,
                RediDomainCert::STATUS_FAILED,
                RediDomainCert::STATUS_DEPLOYING,
                RediDomainCert::STATUS_VALIDATING,
            ])
            ->count();

        $certsExpiringSoon = (clone $certQuery)
            ->whereIn('status', [
                RediDomainCert::STATUS_ACTIVE,
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(14))
            ->where('expires_at', '>=', now())
            ->count();

        return [
            'domains_enabled' => $domainsEnabled,
            'domains_disabled' => $domainsDisabled,
            'certs_active' => $certsActive,
            'certs_needs_attention' => $certsNeedsAttention,
            'certs_expiring_soon' => $certsExpiringSoon,
        ];
    }
}
