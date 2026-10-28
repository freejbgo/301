<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;

class DomainCertificateRedeployQueue
{
    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{queued: int, skipped: int, truncated: int}
     */
    public static function redeployOwned(array $domainIds, int $sessionAdminId, ?bool $isPlatformAdministrator = null): array
    {
        $capped = BatchSelectionLimiter::intIdsCapped($domainIds, DomainBatchMutator::MAX_IDS_PER_BATCH);

        $query = RediAdminDomain::query()
            ->where('status', 1)
            ->whereIn('id', $capped['ids'])
            ->with('certificate');

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();
        if (! $isPlatformAdministrator) {
            $query->where('admin_id', $sessionAdminId);
        }

        $deployableStatuses = [
            RediDomainCert::STATUS_ISSUED,
            RediDomainCert::STATUS_DEPLOYING,
            RediDomainCert::STATUS_ACTIVE,
            RediDomainCert::STATUS_FAILED,
        ];

        $deployService = app(CertificateDeployService::class);
        $queued = 0;
        $skipped = 0;

        foreach ($query->orderBy('id')->get() as $domain) {
            $cert = $domain->certificate;
            if (! $cert
                || ! in_array($cert->status, $deployableStatuses, true)
                || ! CertificateDeployService::certIsEligibleForDeployQueue($cert)) {
                $skipped++;
                continue;
            }

            if ($deployService->queueDeploy($cert)) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'truncated' => $capped['truncated'],
        ];
    }
}
