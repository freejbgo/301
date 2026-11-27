<?php

namespace App\Admin\Actions\Grid;

use App\Models\RediNode;
use App\Support\NodeInstallCommand;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class RotateNodeToken extends RowAction
{
    public function title()
    {
        return __('redi-admin-node.actions.rotate_token.title');
    }

    public function confirm()
    {
        return [__('redi-admin-node.actions.rotate_token.confirm')];
    }

    public function handle(Request $request)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error(__('redi-admin-node.actions.rotate_token.error_admin'));
        }

        $node = RediNode::find($this->getKey());
        if (! $node) {
            return $this->response()->error(__('redi-admin-node.actions.rotate_token.error_not_found'));
        }

        $plain = $node->issueApiTokenPlain();
        $node->save();

        return $this->response()
            ->success(__('redi-admin-node.actions.rotate_token.success'))
            ->detail(NodeInstallCommand::dialogHtml($plain))
            ->alert();
    }
}
