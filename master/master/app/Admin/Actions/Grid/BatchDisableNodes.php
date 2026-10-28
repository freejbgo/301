<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsNodeBatchTruncationNotice;
use App\Admin\Concerns\AdminOnlyGridAction;
use App\Services\NodeBatchMutator;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchDisableNodes extends BatchAction
{
    use AdminOnlyGridAction;
    use AppendsNodeBatchTruncationNotice;

    public function title()
    {
        return __('redi-admin-node.actions.batch_disable.title');
    }

    public function confirm()
    {
        return [__('redi-admin-node.actions.batch_disable.confirm')];
    }

    public function handle(Request $request)
    {
        if ($deny = $this->ensureAdministratorResponse()) {
            return $deny;
        }

        $mutator = new NodeBatchMutator();
        $result = $mutator->setEnabled((array) $this->getKey(), false);

        if ($result['updated'] === 0) {
            return $this->response()->error(__('redi-admin-node.actions.batch_disable.error_none'));
        }

        $msg = __('redi-admin-node.actions.batch_disable.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-node.actions.batch_common.skipped', ['count' => $result['skipped']]);
        }

        $msg = $this->withNodeTruncationNotice($msg, (int) ($result['truncated'] ?? 0));

        return $this->response()->success($msg)->refresh();
    }
}
