<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsBatchTruncationNotice;
use App\Services\DomainCertificateQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchApplyCertificate extends BatchAction
{
    use AppendsBatchTruncationNotice;
    public function title()
    {
        return __('redi-admin-domain.actions.batch_apply.title');
    }

    public function handle(Request $request)
    {
        $dispatch = DomainCertificateQueue::dispatchOwned((array) $this->getKey(), (int) Admin::user()->id);
        if ($dispatch['dispatched'] === 0) {
            return $this->response()->error(__('redi-admin-domain.actions.batch_apply.error'));
        }

        $summary = $this->withTruncationNotice(
            __('redi-admin-domain.actions.batch_apply.success', ['count' => $dispatch['dispatched']]),
            $dispatch['truncated']
        );

        return $this->response()->success($summary)->refresh();
    }
}
