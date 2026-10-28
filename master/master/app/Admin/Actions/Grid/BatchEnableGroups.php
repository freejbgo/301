<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AppendsGroupBatchTruncationNotice;
use App\Services\GroupBatchMutator;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchEnableGroups extends BatchAction
{
    use AppendsGroupBatchTruncationNotice;
    public function title()
    {
        return __('redi-admin-group.actions.batch_enable.title');
    }

    public function handle(Request $request)
    {
        $mutator = new GroupBatchMutator();
        $result = $mutator->setStatusOwned((array) $this->getKey(), 1, (int) Admin::user()->id);

        return $this->respond($result, 'batch_enable');
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[], truncated?: int}  $result
     */
    protected function respond(array $result, string $actionKey)
    {
        if ($result['updated'] === 0) {
            return $this->response()->error(__('redi-admin-group.actions.'.$actionKey.'.error_none'));
        }

        $msg = __('redi-admin-group.actions.'.$actionKey.'.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-group.actions.batch_common.skipped', ['count' => $result['skipped']]);
        }
        if ($result['errors'] !== []) {
            $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 5));
        }

        $msg = $this->withGroupTruncationNotice($msg, (int) ($result['truncated'] ?? 0));

        return $this->response()->success($msg)->refresh();
    }
}
