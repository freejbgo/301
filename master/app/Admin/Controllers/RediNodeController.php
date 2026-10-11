<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Grid\RotateNodeToken;
use App\Admin\Concerns\EnsuresAdministrator;
use App\Models\RediNode;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;

class RediNodeController extends AdminController
{
    use EnsuresAdministrator;

    public function title()
    {
        return '节点管理';
    }

    protected function grid()
    {
        $this->ensureAdministrator();

        return Grid::make(new RediNode(), function (Grid $grid) {
            $grid->column('id')->sortable();
            $grid->column('name', '名称');
            $grid->column('public_ip', '公网 IP');
            $grid->column('enabled', '启用')->switch();
            $grid->column('last_heartbeat_at', '最后心跳');
            $grid->column('agent_version', 'Agent 版本');
            $grid->column('updated_at', '更新时间');

            $grid->disableViewButton();
            $grid->actions(function ($actions) {
                $actions->append(new RotateNodeToken());
            });
        });
    }

    protected function form()
    {
        $this->ensureAdministrator();

        return Form::make(new RediNode(), function (Form $form) {
            $form->text('name', '名称')->required();
            $form->ip('public_ip', '公网 IP')->required();
            $form->switch('enabled', '启用')->default(1);
            $form->textarea('note', '备注');

            $form->saved(function (Form $form) {
                if ($form->isCreating()) {
                    $node = RediNode::find($form->getKey());
                    if ($node) {
                        $plainToken = $node->issueApiTokenPlain();
                        $node->save();
                        admin_toastr('节点 API Token（仅显示一次，请保存）：'.$plainToken, 'warning', ['timeOut' => 0]);
                    }
                }
            });
        });
    }
}
