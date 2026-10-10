<?php

namespace App\Admin\Actions\Grid;

use App\Jobs\IssueDomainCertificateJob;
use Dcat\Admin\Actions\Response;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchApplyCertificate extends BatchAction
{
    protected $title = '批量申请证书';

    public function handle(Request $request)
    {
        $count = 0;
        foreach ((array) $this->getKey() as $id) {
            IssueDomainCertificateJob::dispatch((int) $id);
            $count++;
        }

        return $this->response()->success("已将 {$count} 个域名加入证书队列")->refresh();
    }
}
