<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsBatchTruncationNotice;
use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\DomainBatchMutator;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchEnableDomains extends BatchAction
{
    use AppendsBatchTruncationNotice;
    use RecordsAdminBatchAudit;
    public function title()
    {
        return __('redi-admin-domain.actions.batch_enable.title');
    }

    public function handle(Request $request)
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->setStatusOwned(
            (array) $this->getKey(),
            1,
            (int) Admin::user()->id
        );

        return $this->respondFromMutatorResult($result, 'batch_enable');
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[]}  $result
     */
    protected function respondFromMutatorResult(array $result, string $actionKey)
    {
        if ($result['updated'] === 0 && $result['errors'] === []) {
            return $this->response()->error(__('redi-admin-domain.actions.'.$actionKey.'.error_none'));
        }

        if ($result['updated'] === 0 && $result['errors'] !== []) {
            $msg = __('redi-admin-domain.actions.'.$actionKey.'.error_all');
            $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 8));
            if (count($result['errors']) > 8) {
                $msg .= "\n…";
            }

            return $this->response()->error($msg);
        }

        $summary = __('redi-admin-domain.actions.'.$actionKey.'.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $summary .= __('redi-admin-domain.actions.batch_mutator.skipped_suffix', ['count' => $result['skipped']]);
        }
        if ($result['errors'] !== []) {
            $summary .= "\n".__('redi-admin-domain.actions.batch_mutator.partial_errors', [
                'count' => count($result['errors']),
            ]);
            $summary .= "\n".implode("\n", array_slice($result['errors'], 0, 5));
        }

        $summary = $this->withTruncationNotice($summary, (int) ($result['truncated'] ?? 0));

        $this->auditFromMutator('domain', $actionKey, $result, (array) $this->getKey());

        return $this->response()->success($summary)->refresh();
    }
}
