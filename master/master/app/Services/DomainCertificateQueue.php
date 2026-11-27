<?php

namespace App\Services;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;

class DomainCertificateQueue
{
    /**
     * 为当前管理员名下的域名入队；超级管理员可为任意租户域名入队。
     *
     * @param  array<int, int|string>  $domainIds
     * @return array{dispatched: int, truncated: int}
     */
    public static function dispatchOwned(array $domainIds, int $sessionAdminId, ?bool $isPlatformAdministrator = null): array
    {
        $capped = BatchSelectionLimiter::intIdsCapped($domainIds, DomainBatchMutator::MAX_IDS_PER_BATCH);

        $query = RediAdminDomain::query()
            ->where('status', 1)
            ->whereIn('id', $capped['ids']);

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();
        if (! $isPlatformAdministrator) {
            $query->where('admin_id', $sessionAdminId);
        }

        $ids = $query->orderBy('id')->pluck('id');

        $i = 0;
        foreach ($ids as $id) {
            IssueDomainCertificateJob::dispatch((int) $id)
                ->delay(now()->addSeconds($i * 20));
            $i++;
        }

        return [
            'dispatched' => $i,
            'truncated' => $capped['truncated'],
        ];
    }

    /**
     * 原始域名改了之后，只给已经申请过证书的启用域名重新入队。
     * 没有证书行，或状态仍是「未申请」，不新建证书，也不做 CNAME 检查。
     */
    public static function dispatchIfCertificateRequested(int $domainId, int $delaySeconds = 15): bool
    {
        $domain = RediAdminDomain::query()->find($domainId);
        if (! $domain || (int) $domain->status !== 1) {
            return false;
        }

        $requested = RediDomainCert::query()
            ->where('domain_id', $domainId)
            ->whereNotIn('status', [RediDomainCert::STATUS_NONE, ''])
            ->exists();
        if (! $requested) {
            return false;
        }

        IssueDomainCertificateJob::dispatch($domainId)
            ->delay(now()->addSeconds(max(0, $delaySeconds)));

        return true;
    }
}
