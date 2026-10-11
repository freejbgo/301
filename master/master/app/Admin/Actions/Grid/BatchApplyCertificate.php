<?php

namespace App\Admin\Actions\Grid;

use App\Services\DomainCertificateQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchApplyCertificate extends BatchAction
{
    protected $title = '批量申请证书';

    public function handle(Request $request)
    {
        $count = DomainCertificateQueue::dispatchOwned((array) $this->getKey(), (int) Admin::user()->id);
        if ($count === 0) {
            return $this->response()->error('没有可申请的域名');
        }

        return $this->response()->success("已将 {$count} 个域名加入证书队列（已错开排队）")->refresh();
    }
}
