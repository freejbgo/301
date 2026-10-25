<?php

namespace App\Admin\Actions\Grid;

use App\Services\DomainCertificateQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchApplyCertificate extends BatchAction
{
    public function title()
    {
        return __('redi-admin-domain.actions.batch_apply.title');
    }

    public function handle(Request $request)
    {
        $count = DomainCertificateQueue::dispatchOwned((array) $this->getKey(), (int) Admin::user()->id);
        if ($count === 0) {
            return $this->response()->error(__('redi-admin-domain.actions.batch_apply.error'));
        }

        return $this->response()->success(__('redi-admin-domain.actions.batch_apply.success', ['count' => $count]))->refresh();
    }
}
