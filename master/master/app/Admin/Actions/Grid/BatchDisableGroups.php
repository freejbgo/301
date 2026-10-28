<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsGroupBatchTruncationNotice;
use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\GroupBatchMutator;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchDisableGroups extends BatchAction
{
    use AppendsGroupBatchTruncationNotice;
    use RecordsAdminBatchAudit;
    public function title()
    {
        return __('redi-admin-group.actions.batch_disable.title');
    }

    public function confirm()
    {
        return [__('redi-admin-group.actions.batch_disable.confirm')];
    }

    public function handle(Request $request)
    {
        $mutator = new GroupBatchMutator();
        $result = $mutator->setStatusOwned((array) $this->getKey(), 2, (int) Admin::user()->id);

        if ($result['updated'] === 0) {
            return $this->response()->error(__('redi-admin-group.actions.batch_disable.error_none'));
        }

        $msg = __('redi-admin-group.actions.batch_disable.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-group.actions.batch_common.skipped', ['count' => $result['skipped']]);
        }

        $msg = $this->withGroupTruncationNotice($msg, (int) ($result['truncated'] ?? 0));

        $this->auditFromMutator('group', 'batch_disable', $result, (array) $this->getKey());

        return $this->response()->success($msg)->refresh();
    }
}
