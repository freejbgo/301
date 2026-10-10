<?php

namespace App\Admin\Actions\Grid;

use App\Jobs\IssueDomainCertificateJob;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class ApplyCertificate extends RowAction
{
    protected $title = '申请证书';

    public function handle(Request $request)
    {
        IssueDomainCertificateJob::dispatch((int) $this->getKey());

        return $this->response()->success('已加入证书申请队列')->refresh();
    }
}
