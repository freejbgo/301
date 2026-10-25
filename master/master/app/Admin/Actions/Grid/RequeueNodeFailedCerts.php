<?php

namespace App\Admin\Actions\Grid;

use App\Models\RediNode;
use App\Services\CertificateDeployService;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class RequeueNodeFailedCerts extends RowAction
{
    public function title()
    {
        return __('redi-admin-node.actions.requeue_failed.title');
    }

    public function confirm()
    {
        return [__('redi-admin-node.actions.requeue_failed.confirm')];
    }

    public function handle(Request $request, CertificateDeployService $deployService)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error(__('redi-admin-node.actions.requeue_failed.error_admin'));
        }

        $node = RediNode::find($this->getKey());
        if (! $node) {
            return $this->response()->error(__('redi-admin-node.actions.requeue_failed.error_not_found'));
        }

        $count = $deployService->requeueFailedDeployments((int) $node->id, null);
        if ($count === 0) {
            return $this->response()->warning(__('redi-admin-node.actions.requeue_failed.warning_empty'));
        }

        return $this->response()->success(__('redi-admin-node.actions.requeue_failed.success', ['count' => $count]))->refresh();
    }
}
