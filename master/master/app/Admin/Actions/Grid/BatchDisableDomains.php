<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsBatchTruncationNotice;
use App\Services\DomainBatchMutator;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchDisableDomains extends BatchAction
{
    use AppendsBatchTruncationNotice;
    public function title()
    {
        return __('redi-admin-domain.actions.batch_disable.title');
    }

    public function confirm()
    {
        return [__('redi-admin-domain.actions.batch_disable.confirm')];
    }

    public function handle(Request $request)
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->setStatusOwned(
            (array) $this->getKey(),
            2,
            (int) Admin::user()->id
        );

        if ($result['updated'] === 0) {
            $msg = $result['skipped'] > 0
                ? __('redi-admin-domain.actions.batch_disable.error_already')
                : __('redi-admin-domain.actions.batch_disable.error_none');

            return $this->response()->error($msg);
        }

        $summary = __('redi-admin-domain.actions.batch_disable.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $summary .= __('redi-admin-domain.actions.batch_mutator.skipped_suffix', ['count' => $result['skipped']]);
        }

        $summary = $this->withTruncationNotice($summary, (int) ($result['truncated'] ?? 0));

        return $this->response()->success($summary)->refresh();
    }
}
