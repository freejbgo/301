<?php

namespace App\Admin\Actions\Grid;

use App\Models\RediNode;
use App\Services\CertificateDeployService;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class RequeueNodeFailedCerts extends RowAction
{
    protected $title = '重试失败证书';

    public function confirm()
    {
        return ['将该节点上 status=failed 的证书部署重新标为 pending，节点将重新拉取。继续？'];
    }

    public function handle(Request $request, CertificateDeployService $deployService)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error('仅超级管理员可操作');
        }

        $node = RediNode::find($this->getKey());
        if (! $node) {
            return $this->response()->error('节点不存在');
        }

        $count = $deployService->requeueFailedDeployments((int) $node->id, null);
        if ($count === 0) {
            return $this->response()->warning('该节点没有失败的证书部署记录');
        }

        return $this->response()->success("已重新排队 {$count} 条失败部署")->refresh();
    }
}
