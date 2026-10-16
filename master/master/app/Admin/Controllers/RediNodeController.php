<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Grid\RequeueNodeFailedCerts;
use App\Admin\Actions\Grid\RotateNodeToken;
use App\Admin\Concerns\EnsuresAdministrator;
use App\Models\RediNode;
use App\Services\RediHealthReport;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Illuminate\Validation\ValidationException;

class RediNodeController extends AdminController
{
    use EnsuresAdministrator;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->ensureAdministrator();

            return $next($request);
        });
    }

    public function title()
    {
        return '节点管理';
    }

    protected function grid()
    {
        $this->ensureAdministrator();

        $deployByNode = RediHealthReport::deploymentCountsByNode()->keyBy('node_id');

        return Grid::make(new RediNode(), function (Grid $grid) use ($deployByNode) {
            $grid->column('id')->sortable();
            $grid->column('name', '名称');
            $grid->column('public_ip', '公网 IP')->display(function () {
                return implode('<br>', array_map('e', $this->publicIps()));
            });
            $grid->column('enabled', '启用')->switch();
            $grid->column('last_heartbeat_at', '最后心跳')->display(function ($value) {
                if (! $this->enabled) {
                    return $value ? e((string) $value) : '—';
                }
                if (! $value) {
                    return '<span class="text-danger">从未</span>';
                }
                $at = \Illuminate\Support\Carbon::parse($value);
                $label = e($at->format('Y-m-d H:i'));
                if ($at->gte(now()->subMinutes(15))) {
                    return $label.' <span class="text-success">在线</span>';
                }

                return $label.' <span class="text-warning">离线</span>';
            });
            $grid->column('cert_deploy', '证书部署')->display(function () use ($deployByNode) {
                $row = $deployByNode->get($this->id);
                if (! $row) {
                    return '—';
                }
                $pending = (int) ($row->pending_count ?? 0);
                $failed = (int) ($row->failed_count ?? 0);
                if ($pending === 0 && $failed === 0) {
                    return '<span class="text-success">正常</span>';
                }
                $parts = [];
                if ($pending > 0) {
                    $parts[] = '<span class="text-warning">待部署 '.$pending.'</span>';
                }
                if ($failed > 0) {
                    $parts[] = '<span class="text-danger">失败 '.$failed.'</span>';
                }

                return implode('<br>', $parts);
            });
            $grid->column('agent_version', 'Agent 版本')->display(function ($version) {
                return e((string) $version);
            });
            $grid->column('updated_at', '更新时间');

            $grid->disableViewButton();
            $grid->actions(function ($actions) {
                $actions->append(new RequeueNodeFailedCerts());
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
                ->help('同一台机器上还要写入 pool 的公网 IPv4，一行一个。Pool 只写 A 记录。仍然只跑一个节点进程、只用一个 Token。不要为这些 IP 再新建节点。')
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
                $inputs = (array) $form->input();
                // 列表启用开关只提交 enabled。这里若按空 IP 校验，开关会失败，并可能把其他 IP 写成空。
                if (! array_key_exists('public_ip', $inputs) && ! array_key_exists('extra_ips', $inputs)) {
                    return;
                }

                $primary = trim((string) $form->input('public_ip'));
                if (filter_var($primary, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw ValidationException::withMessages([
                        'public_ip' => '主公网 IP 必须是 IPv4',
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
                        $masked = strlen($plainToken) > 12
                            ? substr($plainToken, 0, 6).'…'.substr($plainToken, -4)
                            : $plainToken;
                        admin_toastr(
                            '节点已创建。完整 Token 仅本次响应可见（请立即写入 REDI_NODE_TOKEN）：'.$masked,
                            'warning',
                            ['timeOut' => 0]
                        );

                        return $form->response()
                            ->success('节点已创建')
                            ->data(['token' => $plainToken]);
                    }
                }
            });
        });
    }
}
