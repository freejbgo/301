<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Grid\RotateNodeToken;
use App\Admin\Concerns\EnsuresAdministrator;
use App\Models\RediNode;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Illuminate\Validation\ValidationException;

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
            $grid->column('public_ip', '公网 IP')->display(function () {
                return implode('<br>', array_map('e', $this->publicIps()));
            });
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
            $form->ip('public_ip', '主公网 IP')->required()->help('这台机器对外的主 IP，也是节点程序所在机器。一台机器只建一条节点。');
            $form->textarea('extra_ips', '其他公网 IP')->rows(4)
                ->help('同一台机器上还要写入 pool 的公网 IP，一行一个。仍然只跑一个节点进程、只用一个 Token。不要为这些 IP 再新建节点。')
                ->customFormat(function ($value) {
                    if (is_array($value)) {
                        return implode("\n", $value);
                    }
                    if (is_string($value) && strncmp($value, '[', 1) === 0) {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            return implode("\n", $decoded);
                        }
                    }

                    return (string) $value;
                });
            $form->switch('enabled', '启用')->default(1);
            $form->textarea('note', '备注');

            $form->saving(function (Form $form) {
                $primary = trim((string) $form->input('public_ip'));
                if (filter_var($primary, FILTER_VALIDATE_IP) === false) {
                    throw ValidationException::withMessages([
                        'public_ip' => '主公网 IP 无效',
                    ]);
                }
                $extras = RediNode::parseExtraIps((string) $form->input('extra_ips'));
                $extras = array_values(array_filter($extras, function ($ip) use ($primary) {
                    return $ip !== $primary;
                }));
                $exceptId = $form->isEditing() ? (int) $form->getKey() : null;
                RediNode::assertIpsAvailable(array_merge([$primary], $extras), $exceptId);
                $form->input('extra_ips', $extras === [] ? null : $extras);
            });

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
