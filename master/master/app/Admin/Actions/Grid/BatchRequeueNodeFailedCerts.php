<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsNodeBatchTruncationNotice;
use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Admin\Concerns\AdminOnlyGridAction;
use App\Services\CertificateDeployService;
use App\Services\NodeBatchMutator;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchRequeueNodeFailedCerts extends BatchAction
{
    use AdminOnlyGridAction;
    use AppendsNodeBatchTruncationNotice;
    use RecordsAdminBatchAudit;

    public function title()
    {
        return __('redi-admin-node.actions.batch_requeue_failed.title');
    }

    public function confirm()
    {
        return [__('redi-admin-node.actions.batch_requeue_failed.confirm')];
    }

    public function handle(Request $request)
    {
        // Dcat 只传入 Request，不会做方法注入。
        $deployService = app(CertificateDeployService::class);
        if ($deny = $this->ensureAdministratorResponse()) {
            return $deny;
        }

        $mutator = new NodeBatchMutator();
        $result = $mutator->requeueFailed((array) $this->getKey(), $deployService);

        if ($result['updated'] === 0) {
            return $this->response()->warning(__('redi-admin-node.actions.batch_requeue_failed.error_none'));
        }

        $msg = __('redi-admin-node.actions.batch_requeue_failed.success', [
            'nodes' => $result['updated'],
            'deployments' => $result['deployments_requeued'],
        ]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-node.actions.batch_common.skipped_nodes', ['count' => $result['skipped']]);
        }

        $msg = $this->withNodeTruncationNotice($msg, (int) ($result['truncated'] ?? 0));

        $this->auditFromMutator('node', 'batch_requeue_failed', $result, (array) $this->getKey());

        return $this->response()->success($msg)->refresh();
    }
}
