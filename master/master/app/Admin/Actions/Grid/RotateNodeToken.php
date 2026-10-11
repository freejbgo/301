<?php

namespace App\Admin\Actions\Grid;

use App\Models\RediNode;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Illuminate\Http\Request;

class RotateNodeToken extends RowAction
{
    protected $title = '重置 Token';

    public function confirm()
    {
        return ['重置后旧 Token 立即失效，节点需改用新 Token。继续？'];
    }

    public function handle(Request $request)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error('仅超级管理员可操作');
        }

        $node = RediNode::find($this->getKey());
        if (! $node) {
            return $this->response()->error('节点不存在');
        }

        $plain = $node->issueApiTokenPlain();
        $node->save();

        return $this->response()
            ->success('新 Token（仅此一次，请立即保存到节点 REDI_NODE_TOKEN）：'.$plain)
            ->refresh();
    }
}
