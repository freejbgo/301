<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Grid\BatchDisableNodes;
use App\Admin\Actions\Grid\BatchEnableNodes;
use App\Admin\Actions\Grid\BatchRequeueNodeFailedCerts;
use App\Admin\Actions\Grid\BatchRotateNodeTokens;
use App\Admin\Actions\Grid\RequeueNodeFailedCerts;
use App\Admin\Actions\Grid\RotateNodeToken;
use App\Admin\Concerns\EnsuresAdministrator;
use App\Models\RediNode;
use App\Services\RediHealthReport;
use App\Support\OpsDisplaySanitizer;
use Dcat\Admin\Form;
use Illuminate\Support\Facades\Schema;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Illuminate\Validation\ValidationException;

class RediNodeController extends AdminController
{
    use EnsuresAdministrator;

    protected $translation = 'redi-admin-node';

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->ensureAdministrator();

            return $next($request);
        });
    }

    public function title()
    {
        return admin_trans_label('title');
    }

    protected function grid()
    {
        $this->ensureAdministrator();

        $deployByNode = RediHealthReport::deploymentCountsByNode()->keyBy('node_id');

        return Grid::make(new RediNode(), function (Grid $grid) use ($deployByNode) {
            $grid->column('id')->sortable();
            $grid->column('name', admin_trans_field('name'))->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 120));
            });
            $grid->column('public_ip', admin_trans_field('public_ip'))->display(function () {
                return implode('<br>', array_map('e', $this->publicIps()));
            });
            $grid->column('public_ipv6s', admin_trans_field('public_ipv6s'))->display(function () {
                $ips = $this->publicIpv6s();
                if ($ips === []) {
                    return '—';
                }

                return implode('<br>', array_map('e', $ips));
            });
            $grid->column('enabled', admin_trans_field('enabled'))->switch();
            $grid->column('last_heartbeat_at', admin_trans_field('last_heartbeat_at'))->display(function ($value) {
                if (! $this->enabled) {
                    return $value ? e((string) $value) : '—';
                }
                if (! $value) {
                    return '<span class="text-danger">'.e(admin_trans_label('heartbeat_never')).'</span>';
                }
                $at = \Illuminate\Support\Carbon::parse($value);
                $label = e($at->format('Y-m-d H:i'));
                if ($at->gte(now()->subMinutes(15))) {
                    return $label.' <span class="text-success">'.e(admin_trans_label('online')).'</span>';
                }

                return $label.' <span class="text-warning">'.e(admin_trans_label('offline')).'</span>';
            });
            $grid->column('cert_deploy', admin_trans_field('cert_deploy'))->display(function () use ($deployByNode) {
                $row = $deployByNode->get($this->id);
                if (! $row) {
                    return '—';
                }
                $pending = (int) ($row->pending_count ?? 0);
                $failed = (int) ($row->failed_count ?? 0);
                if ($pending === 0 && $failed === 0) {
                    return '<span class="text-success">'.e(admin_trans_label('deploy_ok')).'</span>';
                }
                $parts = [];
                if ($pending > 0) {
                    $parts[] = '<span class="text-warning">'.e(__('redi-admin-node.labels.deploy_pending', ['count' => $pending])).'</span>';
                }
                if ($failed > 0) {
                    $parts[] = '<span class="text-danger">'.e(__('redi-admin-node.labels.deploy_failed', ['count' => $failed])).'</span>';
                }

                return implode('<br>', $parts);
            });
            $grid->column('agent_version', admin_trans_field('agent_version'))->display(function ($version) {
                return e(OpsDisplaySanitizer::field($version, '—', 32));
            });
            $grid->column('updated_at', admin_trans_field('updated_at'));

            $requeueConfirm = json_encode(admin_trans_label('requeue_confirm'), JSON_UNESCAPED_UNICODE);
            $grid->tools(function (Grid\Tools $tools) use ($requeueConfirm) {
                $requeueUrl = admin_url('ops/requeue-failed-deployments');
                $token = csrf_token();
                $tools->append(
                    '<form method="post" action="'.e($requeueUrl).'" style="display:inline-block;margin-right:6px" onsubmit="return confirm('.$requeueConfirm.');">'
                    .'<input type="hidden" name="_token" value="'.e($token).'">'
                    .'<input type="hidden" name="_redirect" value="'.e(admin_base_path('nodes')).'">'
                    .'<button type="submit" class="btn btn-warning btn-sm">'.e(admin_trans_label('requeue_btn')).'</button></form>'
                );
            });

            $grid->batchActions(function ($batch) {
                $batch->add(new BatchEnableNodes());
                $batch->add(new BatchDisableNodes());
                $batch->add(new BatchRequeueNodeFailedCerts());
                $batch->add(new BatchRotateNodeTokens());
            });

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
            $form->text('name', admin_trans_field('name'))->required();
            $form->ip('public_ip', __('redi-admin-node.form.fields.public_ip'))->required()
                ->help(__('redi-admin-node.form.help.public_ip'));
            if (Schema::hasColumn('redi_nodes', 'public_ipv6')) {
                $form->text('public_ipv6', __('redi-admin-node.form.fields.public_ipv6'))
                    ->help(__('redi-admin-node.form.help.public_ipv6'));
            }
            if (Schema::hasColumn('redi_nodes', 'extra_ipv6')) {
                $form->textarea('extra_ipv6', __('redi-admin-node.form.fields.extra_ipv6'))->rows(4)
                    ->help(__('redi-admin-node.form.help.extra_ipv6'))
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
            }
            $form->textarea('extra_ips', __('redi-admin-node.form.fields.extra_ips'))->rows(4)
                ->help(__('redi-admin-node.form.help.extra_ips'))
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
            $form->switch('enabled', admin_trans_field('enabled'))->default(1);
            $form->textarea('note', __('redi-admin-node.form.fields.note'));

            $form->saving(function (Form $form) {
                $inputs = (array) $form->input();
                // 列表启用开关只提交 enabled。这里若按空 IP 校验，开关会失败，并可能把其他 IP 写成空。
                if (! array_key_exists('public_ip', $inputs) && ! array_key_exists('extra_ips', $inputs)) {
                    return;
                }

                $primary = trim((string) $form->input('public_ip'));
                if (! RediNode::isValidPublicIpv4($primary)) {
                    throw ValidationException::withMessages([
                        'public_ip' => __('redi-admin-node.form.validation.public_ip_ipv4'),
                    ]);
                }
                $extras = RediNode::parseExtraIps((string) $form->input('extra_ips'));
                $extras = array_values(array_filter($extras, function ($ip) use ($primary) {
                    return $ip !== $primary;
                }));
                $exceptId = $form->isEditing() ? (int) $form->getKey() : null;
                RediNode::assertIpsAvailable(array_merge([$primary], $extras), $exceptId);
                $form->input('extra_ips', $extras === [] ? null : $extras);

                $canonicalV6 = null;
                if (Schema::hasColumn('redi_nodes', 'public_ipv6')) {
                    $canonicalV6 = RediNode::preparePublicIpv6ForStorage($form->input('public_ipv6'));
                    $form->input('public_ipv6', $canonicalV6);
                }

                if (Schema::hasColumn('redi_nodes', 'extra_ipv6')) {
                    $extraV6 = RediNode::parseExtraIpv6((string) $form->input('extra_ipv6'));
                    if ($extraV6 !== [] && $canonicalV6 === null) {
                        throw ValidationException::withMessages([
                            'extra_ipv6' => __('redi-admin-node.form.validation.extra_ipv6_primary'),
                        ]);
                    }
                    if ($canonicalV6 !== null) {
                        $extraV6 = array_values(array_filter($extraV6, function ($ip) use ($canonicalV6) {
                            return $ip !== $canonicalV6;
                        }));
                    }
                    $ipv6Candidates = array_merge(
                        $canonicalV6 !== null ? [$canonicalV6] : [],
                        $extraV6
                    );
                    if ($ipv6Candidates !== []) {
                        RediNode::assertIpv6Available($ipv6Candidates, $exceptId);
                    }
                    $form->input('extra_ipv6', $extraV6 === [] ? null : $extraV6);
                } elseif ($canonicalV6 !== null) {
                    RediNode::assertIpv6Available([$canonicalV6], $exceptId);
                }
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
                            __('redi-admin-node.form.created_toastr', ['masked' => $masked]),
                            'warning',
                            ['timeOut' => 0]
                        );

                        return $form->response()
                            ->success(__('redi-admin-node.form.created'))
                            ->data(['token' => $plainToken]);
                    }
                }
            });
        });
    }
}
