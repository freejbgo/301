<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Concerns\AdminOnlyGridAction;
use App\Models\RediNode;
use App\Services\NodeBatchMutator;
use Dcat\Admin\Grid\BatchAction;
use Illuminate\Http\Request;

class BatchRotateNodeTokens extends BatchAction
{
    use AdminOnlyGridAction;

    public function title()
    {
        return __('redi-admin-node.actions.batch_rotate_token.title');
    }

    public function confirm()
    {
        return [__('redi-admin-node.actions.batch_rotate_token.confirm', [
            'max' => NodeBatchMutator::MAX_TOKEN_ROTATE_BATCH,
        ])];
    }

    public function handle(Request $request)
    {
        if ($deny = $this->ensureAdministratorResponse()) {
            return $deny;
        }

        $mutator = new NodeBatchMutator();
        $result = $mutator->rotateTokens((array) $this->getKey());

        if ($result['updated'] === 0) {
            return $this->response()->error(__('redi-admin-node.actions.batch_rotate_token.error_none'));
        }

        $lines = [];
        foreach ($result['tokens'] as $nodeId => $plain) {
            $node = RediNode::query()->find($nodeId);
            $name = $node ? (string) $node->name : '#'.$nodeId;
            $lines[] = $name.' (#'.$nodeId.'): '.$plain;
        }

        $msg = __('redi-admin-node.actions.batch_rotate_token.success', ['count' => $result['updated']]);
        $msg .= "\n".implode("\n", $lines);
        if ($result['errors'] !== []) {
            $msg .= "\n".implode("\n", $result['errors']);
        }

        return $this->response()->success($msg)->refresh();
    }
}
