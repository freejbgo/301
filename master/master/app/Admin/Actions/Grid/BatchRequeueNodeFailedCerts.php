<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AdminOnlyGridAction;
use App\Services\CertificateDeployService;
use App\Services\NodeBatchMutator;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchRequeueNodeFailedCerts extends BatchAction
{
    use AdminOnlyGridAction;

    public function title()
    {
        return __('redi-admin-node.actions.batch_requeue_failed.title');
    }

    public function confirm()
    {
        return [__('redi-admin-node.actions.batch_requeue_failed.confirm')];
    }

    public function handle(Request $request, CertificateDeployService $deployService)
    {
        if ($deny = $this->ensureAdministratorResponse()) {
            return $deny;
        }

        $mutator = new NodeBatchMutator();
        $result = $mutator->requeueFailed((array) $this->getKey(), $deployService);

        if ($result['updated'] === 0) {
            return $this->response()->warning(__('redi-admin-node.actions.batch_requeue_failed.error_none'));
        }

        $msg = __('redi-admin-node.actions.batch_requeue_failed.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-node.actions.batch_common.skipped_nodes', ['count' => $result['skipped']]);
        }

        return $this->response()->success($msg)->refresh();
    }
}
