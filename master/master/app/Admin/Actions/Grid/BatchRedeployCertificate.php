<?php

namespace App\Admin\Actions\Grid;

use App\Services\DomainCertificateRedeployQueue;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchRedeployCertificate extends BatchAction
{
    protected $title = '批量重新下发证书';

    public function confirm()
    {
        return ['仅对已签发且可解密的证书向全部启用节点重新排队部署。继续？'];
    }

    public function handle(Request $request)
    {
        $result = DomainCertificateRedeployQueue::redeployOwned(
            (array) $this->getKey(),
            (int) Admin::user()->id
        );

        if ($result['queued'] === 0) {
            $msg = $result['skipped'] > 0
                ? '所选域名均无法重新下发（无证书、未签发或密文不可读）'
                : '没有可操作的域名';

            return $this->response()->error($msg);
        }

        $summary = "已重新下发 {$result['queued']} 个域名的证书";
        if ($result['skipped'] > 0) {
            $summary .= "，跳过 {$result['skipped']} 个";
        }

        return $this->response()->success($summary)->refresh();
    }
}
