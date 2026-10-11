<?php

namespace App\Admin\Actions\Grid;

use App\Services\DomainCertificateQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class ApplyCertificate extends RowAction
{
    protected $title = '申请证书';

    public function handle(Request $request)
    {
        $count = DomainCertificateQueue::dispatchOwned([(int) $this->getKey()], (int) Admin::user()->id);
        if ($count === 0) {
            return $this->response()->error('域名不存在或无权申请');
        }

        return $this->response()->success('已加入证书申请队列')->refresh();
    }
}
