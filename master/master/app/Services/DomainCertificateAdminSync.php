<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;

/**
 * 与域名表单保存后相同的证书对齐逻辑（批量迁组等路径也需调用）。
 */
class DomainCertificateAdminSync
{
    public static function realignCertsForDomain(RediAdminDomain $domain): void
    {
        RediDomainCert::query()
            ->where('domain_id', $domain->id)
            ->get()
            ->each(function (RediDomainCert $cert) use ($domain) {
                $cert->setRelation('domain', $domain);
                app(CertificateDeployService::class)->freshCertWithPersistedRealign($cert);
                if ((int) $cert->admin_id !== (int) $domain->admin_id) {
                    $cert->admin_id = (int) $domain->admin_id;
                    $cert->save();
                }
            });
    }
}
