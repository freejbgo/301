<?php

namespace App\Services;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediAdminDomain;
use App\Support\AdminTenantContext;

class DomainCertificateQueue
{
    /**
     * 为当前管理员名下的域名入队；超级管理员可为任意租户域名入队。
     *
     * @param  array<int, int|string>  $domainIds
     */
    public static function dispatchOwned(array $domainIds, int $sessionAdminId, ?bool $isPlatformAdministrator = null): int
    {
        $query = RediAdminDomain::query()
            ->where('status', 1)
            ->whereIn('id', $domainIds);

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

        return $i;
    }
}
