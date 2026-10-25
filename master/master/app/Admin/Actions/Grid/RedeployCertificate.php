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
    public function title()
    {
        return __('redi-admin-domain.actions.redeploy.title');
    }

    public function confirm()
    {
        return [__('redi-admin-domain.actions.redeploy.confirm')];
    }

    public function handle(Request $request, CertificateDeployService $deployService)
    {
        $domain = RediAdminDomain::query()->find($this->getKey());
        if (! $domain) {
            return $this->response()->error(__('redi-admin-domain.actions.redeploy.error_not_found'));
        }

        if (! AdminTenantContext::isPlatformAdministrator()
            && (int) $domain->admin_id !== AdminTenantContext::sessionAdminId()) {
            return $this->response()->error(__('redi-admin-domain.actions.redeploy.error_forbidden'));
        }

        if ((int) $domain->status !== 1) {
            return $this->response()->error(__('redi-admin-domain.actions.redeploy.error_disabled'));
        }

        $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
        if (! $cert) {
            return $this->response()->error(__('redi-admin-domain.actions.redeploy.error_no_cert'));
        }

        if (! in_array($cert->status, [
            RediDomainCert::STATUS_ISSUED,
            RediDomainCert::STATUS_DEPLOYING,
            RediDomainCert::STATUS_ACTIVE,
            RediDomainCert::STATUS_FAILED,
        ], true)) {
            return $this->response()->error(__('redi-admin-domain.actions.redeploy.error_bad_status').$cert->statusLabel());
        }

        $cert->loadMissing('domain');
        if (! CertificateDeployService::certIsEligibleForDeployQueue($cert)) {
            return $this->response()->error(__('redi-admin-domain.actions.redeploy.error_not_deployable'));
        }

        if (! $deployService->queueDeploy($cert)) {
            return $this->response()->error((string) ($cert->fresh()->last_error ?: __('redi-admin-domain.actions.redeploy.error_queue')));
        }

        return $this->response()->success(__('redi-admin-domain.actions.redeploy.success'))->refresh();
    }
}
