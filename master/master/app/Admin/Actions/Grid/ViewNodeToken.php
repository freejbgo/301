<?php

namespace App\Admin\Actions\Grid;

use App\Models\RediNode;
use App\Support\NodeInstallCommand;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class ViewNodeToken extends RowAction
{
    public function title()
    {
        return __('redi-admin-node.actions.view_token.title');
    }

    public function handle(Request $request)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error(__('redi-admin-node.actions.view_token.error_admin'));
        }

        $node = RediNode::find($this->getKey());
        if (! $node) {
            return $this->response()->error(__('redi-admin-node.actions.view_token.error_not_found'));
        }

        $plain = $node->revealApiToken();
        if ($plain === null) {
            return $this->response()
                ->warning(__('redi-admin-node.actions.view_token.missing'))
                ->alert();
        }

        return $this->response()
            ->success(__('redi-admin-node.actions.view_token.success', ['name' => (string) $node->name]))
            ->detail(NodeInstallCommand::dialogHtml($plain))
            ->alert();
    }
}
