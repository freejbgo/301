<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsBatchTruncationNotice;
use App\Services\DomainCertificateRedeployQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchRedeployCertificate extends BatchAction
{
    use AppendsBatchTruncationNotice;
    public function title()
    {
        return __('redi-admin-domain.actions.batch_redeploy.title');
    }

    public function confirm()
    {
        return [__('redi-admin-domain.actions.batch_redeploy.confirm')];
    }

    public function handle(Request $request)
    {
        $result = DomainCertificateRedeployQueue::redeployOwned(
            (array) $this->getKey(),
            (int) Admin::user()->id
        );

        if ($result['queued'] === 0) {
            $msg = $result['skipped'] > 0
                ? __('redi-admin-domain.actions.batch_redeploy.error_all_skip')
                : __('redi-admin-domain.actions.batch_redeploy.error_none');

            return $this->response()->error($msg);
        }

        $summary = __('redi-admin-domain.actions.batch_redeploy.success', ['queued' => $result['queued']]);
        if ($result['skipped'] > 0) {
            $summary .= __('redi-admin-domain.actions.batch_redeploy.success_skipped', ['skipped' => $result['skipped']]);
        }
        $summary = $this->withTruncationNotice($summary, (int) ($result['truncated'] ?? 0));

        return $this->response()->success($summary)->refresh();
    }
}
