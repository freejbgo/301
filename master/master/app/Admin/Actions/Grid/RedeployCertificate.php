<?php

namespace App\Admin\Actions\Grid;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Services\CertificateDeployService;
use App\Support\AdminTenantContext;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class RedeployCertificate extends RowAction
{
    protected $title = '重新下发证书';

    public function confirm()
    {
        return ['将向全部启用节点重新排队该域名的证书（pending）。继续？'];
    }

    public function handle(Request $request, CertificateDeployService $deployService)
    {
        $domain = RediAdminDomain::query()->find($this->getKey());
        if (! $domain) {
            return $this->response()->error('域名不存在');
        }

        if (! AdminTenantContext::isPlatformAdministrator()
            && (int) $domain->admin_id !== AdminTenantContext::sessionAdminId()) {
            return $this->response()->error('无权操作该域名');
        }

        if ((int) $domain->status !== 1) {
            return $this->response()->error('域名未启用，无法重新下发证书');
        }

        $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
        if (! $cert) {
            return $this->response()->error('尚未申请证书');
        }

        if (! $cert->getCertificatePem() || ! $cert->getPrivateKeyPem()) {
            return $this->response()->error('证书密文不可用，请先重新申请证书');
        }

        if (! in_array($cert->status, [
            RediDomainCert::STATUS_ISSUED,
            RediDomainCert::STATUS_DEPLOYING,
            RediDomainCert::STATUS_ACTIVE,
            RediDomainCert::STATUS_FAILED,
        ], true)) {
            return $this->response()->error('当前证书状态不支持重新下发：'.$cert->statusLabel());
        }

        if (! $deployService->queueDeploy($cert)) {
            return $this->response()->error((string) ($cert->fresh()->last_error ?: '无法排队证书部署'));
        }

        return $this->response()->success('已向全部启用节点重新排队证书部署')->refresh();
    }
}
