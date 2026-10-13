<?php

namespace App\Services;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediAdminDomain;

class DomainCertificateQueue
{
    /**
     * 只为当前管理员名下的域名入队，并错开启动时间以降低 ACME 限流。
     *
     * @param  array<int, int|string>  $domainIds
     */
    public static function dispatchOwned(array $domainIds, int $adminId): int
    {
        $ids = RediAdminDomain::query()
            ->where('admin_id', $adminId)
            ->where('status', 1)
            ->whereIn('id', $domainIds)
            ->orderBy('id')
            ->pluck('id');

        $i = 0;
        foreach ($ids as $id) {
            IssueDomainCertificateJob::dispatch((int) $id)
                ->delay(now()->addSeconds($i * 20));
            $i++;
        }

        return $i;
    }
}
