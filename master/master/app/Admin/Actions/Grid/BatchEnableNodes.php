<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsNodeBatchTruncationNotice;
use App\Admin\Concerns\AdminOnlyGridAction;
use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\NodeBatchMutator;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchEnableNodes extends BatchAction
{
    use AdminOnlyGridAction;
    use AppendsNodeBatchTruncationNotice;
    use RecordsAdminBatchAudit;

    public function title()
    {
        return __('redi-admin-node.actions.batch_enable.title');
    }

    public function handle(Request $request)
    {
        if ($deny = $this->ensureAdministratorResponse()) {
            return $deny;
        }

        $mutator = new NodeBatchMutator();
        $result = $mutator->setEnabled((array) $this->getKey(), true);

        return $this->summaryResponse($result, 'batch_enable');
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[]}  $result
     */
    protected function summaryResponse(array $result, string $key)
    {
        if ($result['updated'] === 0) {
            return $this->response()->error(__('redi-admin-node.actions.'.$key.'.error_none'));
        }

        $msg = __('redi-admin-node.actions.'.$key.'.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-node.actions.batch_common.skipped', ['count' => $result['skipped']]);
        }

        $msg = $this->withNodeTruncationNotice($msg, (int) ($result['truncated'] ?? 0));

        $this->auditFromMutator('node', $key, $result, (array) $this->getKey());

        return $this->response()->success($msg)->refresh();
    }
}
