<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsGroupBatchTruncationNotice;
use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\GroupBatchMutator;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchDeleteGroups extends BatchAction
{
    use AppendsGroupBatchTruncationNotice;
    use RecordsAdminBatchAudit;
    public function title()
    {
        return __('redi-admin-group.actions.batch_delete.title');
    }

    public function confirm()
    {
        return [__('redi-admin-group.actions.batch_delete.confirm')];
    }

    public function handle(Request $request)
    {
        $mutator = new GroupBatchMutator();
        $result = $mutator->deleteOwned((array) $this->getKey(), (int) Admin::user()->id);

        if ($result['updated'] === 0) {
            if ($result['errors'] !== []) {
                $msg = implode("\n", array_slice($result['errors'], 0, 6));
            } else {
                $msg = __('redi-admin-group.actions.batch_delete.error_none');
            }

            return $this->response()->error($msg)->alert();
        }

        $msg = __('redi-admin-group.actions.batch_delete.success', ['count' => $result['updated']]);
        if ($result['errors'] !== []) {
            $msg .= "\n".__('redi-admin-group.actions.batch_common.partial_errors', [
                'count' => count($result['errors']),
            ]);
            $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 6));
        }

        $msg = $this->withGroupTruncationNotice($msg, (int) ($result['truncated'] ?? 0));

        $this->auditFromMutator('group', 'batch_delete', $result, (array) $this->getKey());

        $response = $this->response()->success($msg)->refresh();
        if ($result['errors'] !== []) {
            $response->alert();
        }

        return $response;
    }
}
