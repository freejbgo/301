<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsBatchTruncationNotice;
use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\DomainBatchMutator;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchDeleteDomains extends BatchAction
{
    use AppendsBatchTruncationNotice;
    use RecordsAdminBatchAudit;
    public function title()
    {
        return __('redi-admin-domain.actions.batch_delete.title');
    }

    public function confirm()
    {
        return [__('redi-admin-domain.actions.batch_delete.confirm')];
    }

    public function handle(Request $request)
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->deleteOwned((array) $this->getKey(), (int) Admin::user()->id);

        if ($result['updated'] === 0) {
            $msg = $result['errors'] !== []
                ? __('redi-admin-domain.actions.batch_delete.error_all')
                : __('redi-admin-domain.actions.batch_delete.error_none');
            if ($result['errors'] !== []) {
                $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 6));
            }

            return $this->response()->error($msg);
        }

        $summary = __('redi-admin-domain.actions.batch_delete.success', ['count' => $result['updated']]);
        if ($result['errors'] !== []) {
            $summary .= "\n".__('redi-admin-domain.actions.batch_mutator.partial_errors', [
                'count' => count($result['errors']),
            ]);
        }

        $summary = $this->withTruncationNotice($summary, (int) ($result['truncated'] ?? 0));

        $this->auditFromMutator('domain', 'batch_delete', $result, (array) $this->getKey());

        return $this->response()->success($summary)->refresh();
    }
}
