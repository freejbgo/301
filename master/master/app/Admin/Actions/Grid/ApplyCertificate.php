<?php

namespace App\Admin\Actions\Grid;

use App\Services\DomainCertificateQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class ApplyCertificate extends RowAction
{
    public function title()
    {
        return __('redi-admin-domain.actions.apply_cert.title');
    }

    public function handle(Request $request)
    {
        $dispatch = DomainCertificateQueue::dispatchOwned([(int) $this->getKey()], (int) Admin::user()->id);
        if ($dispatch['dispatched'] === 0) {
            return $this->response()->error(__('redi-admin-domain.actions.apply_cert.error'));
        }

        return $this->response()->success(__('redi-admin-domain.actions.apply_cert.success'))->refresh();
    }
}
