<?php

namespace App\Admin\Controllers;

use App\Admin\Repositories\RediAdminDomain;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Widgets\Dropdown;
use Illuminate\Support\Facades\DB;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use App\Admin\Actions\Modal\BatchDomainModal;
use App\Models\RediDomainCert;
use App\Services\DomainDnsPointingGuard;
use App\Services\DomainOriginalUrlGuard;
use App\Services\DomainRecordNormalizer;
use App\Services\CnamePrecheckService;
use App\Services\TenantEdgeService;
use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;
use App\Admin\Actions\Grid\ApplyCertificate;
use App\Admin\Actions\Grid\BatchApplyCertificate;
use App\Admin\Actions\Grid\BatchRedeployCertificate;
use App\Admin\Actions\Grid\RedeployCertificate;
use App\Jobs\IssueDomainCertificateJob;
use App\Services\DomainListCsvExporter;
use Illuminate\Http\Request;
use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use Illuminate\Validation\ValidationException;


class RediAdminDomainController extends AdminController
{
    public function title()
    {
        return '域名管理';
    }

    public function exportCsv(Request $request)
    {
        return DomainListCsvExporter::streamedResponse($request);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new RediAdminDomain(), function (Grid $grid) {
            
            $grid->tools(function (Grid\Tools $tools) {
                $tools->append(new BatchDomainModal());
                $exportQuery = http_build_query(array_filter(
                    request()->only(['group_id', 'original_url', 'skip_url', 'status', 'cert_filter']),
                    function ($v) {
                        return $v !== null && $v !== '';
                    }
                ));
                $exportUrl = admin_url('domain/export'.($exportQuery !== '' ? '?'.$exportQuery : ''));
                $tools->append(
                    '<a href="'.e($exportUrl).'" class="btn btn-default btn-sm" style="margin-left:6px"><i class="feather icon-download"></i> 导出 CSV</a>'
                );
            });

            $grid->batchActions(function ($batch) {
                $batch->add(new BatchApplyCertificate());
                $batch->add(new BatchRedeployCertificate());
            });

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->append(new ApplyCertificate());
                $cert = $this->certificate;
                if ($cert && in_array($cert->status, [
                    \App\Models\RediDomainCert::STATUS_ISSUED,
                    \App\Models\RediDomainCert::STATUS_DEPLOYING,
                    \App\Models\RediDomainCert::STATUS_ACTIVE,
                    \App\Models\RediDomainCert::STATUS_FAILED,
                ], true)) {
                    $actions->append(new RedeployCertificate());
                }
            });
            
            $grid->model()->with(['group', 'certificate']);

            $grid->column('id')->sortable();
            $grid->column('group_id', '分组名称')->display(function () {
                $name = $this->group?->name;

                return e(OpsDisplaySanitizer::field($name, '（分组不存在）', 120));
            });
            $grid->column('original_url', '原始域名')->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 253));
            });
            $grid->column('cname_target', 'CNAME 目标')->display(function () {
                $target = TenantEdgeService::cnameTargetForAdmin((int) $this->admin_id);
                if ($target === '' || strncmp($target, '（', 3) === 0) {
                    return $target;
                }
                $safe = OpsDisplaySanitizer::field($target, '', 253);

                return e($safe).' <a href="javascript:;" class="redi-copy-cname" data-copy="'.e($safe).'">复制</a>';
            });
            $grid->column('cname_ok', '解析预检')->display(function () {
                $edgeFqdn = TenantEdgeService::cnameTargetForAdmin((int) $this->admin_id);
                if ($edgeFqdn === '' || strncmp($edgeFqdn, '（', 3) === 0) {
                    return '—';
                }
                $ok = CnamePrecheckService::pointsToEdgeCached($this->original_url, $edgeFqdn);

                return $ok ? '已指向' : '未检测到';
            });
            $grid->column('skip_url', '跳转地址')->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 500));
            });
            $grid->column('cert_status', '证书')->display(function () {
                $cert = $this->certificate;
                if (! $cert) {
                    return '未申请';
                }
                $label = e($cert->statusLabel());
                if ($cert->storedCredentialsUnreadable()) {
                    $label .= '<br><span class="text-danger">证书密文无法解密（检查 APP_KEY 是否变更）</span>';
                } elseif ($cert->last_error) {
                    $label .= '<br><span class="text-danger">'.e(OpsDisplaySanitizer::field($cert->last_error, '—', 500)).'</span>';
                }
                if ($cert->expires_at) {
                    $exp = $cert->expires_at;
                    $label .= '<br><span class="text-muted">到期 '.e($exp->format('Y-m-d')).'</span>';
                    if ($exp->isPast()) {
                        $label .= ' <span class="text-danger">已过期</span>';
                    } elseif ($exp->lte(now()->addDays(14))) {
                        $label .= ' <span class="text-warning">即将到期</span>';
                    }
                }

                return $label;
            });
            $grid->column('skip_num', '跳转次数');
            $grid->column('status', '状态')->using([1 => "启用", 2 => "停用"]);
            $grid->column('admin_name', '添加人')->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 120));
            });
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();

            Admin::script(<<<'JS'
Dcat.ready(function () {
  $(document).off('click', '.redi-copy-cname').on('click', '.redi-copy-cname', function () {
    var text = $(this).data('copy') || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { Dcat.success('已复制 CNAME 目标'); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      Dcat.success('已复制 CNAME 目标');
    }
  });
});
JS
            );

            $grid->filter(function (Grid\Filter $filter) {
                $filter->panel();

                $filter->equal('group_id', '分组')->select(AdminTenantContext::groupSelectOptions())->width(3);

                $filter->like('original_url', '原始域名')->width(4);
                $filter->like('skip_url', '跳转地址')->width(4);
                $filter->equal('status', '状态')->select([1 => '启用', 2 => '停用'])->width(3);

                $filter->where('cert_filter', function ($query) {
                    \App\Models\RediAdminDomain::applyCertificateListFilter($query, $this->input);
                }, '证书')->select([
                    '' => '全部',
                    'needs_attention' => '待处理',
                    'active' => '已生效',
                    'expiring' => '14 天内到期',
                    'none' => '未申请',
                ])->width(3);

            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new RediAdminDomain(), function (Show $show) {
            $show->field('id');
            $show->field('group_id');
            $show->field('original_url');
            $show->field('skip_url');
            $show->field('status');
            $show->field('admin_id');
            $show->field('admin_name');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new RediAdminDomain(), function (Form $form) {
            $previousOriginalUrl = null;

            $groupOwnerId = null;
            if (! AdminTenantContext::isPlatformAdministrator()) {
                $groupOwnerId = $form->isEditing() && $form->model()->admin_id
                    ? (int) $form->model()->admin_id
                    : AdminTenantContext::sessionAdminId();
            }
            $groupList = AdminTenantContext::groupSelectOptions($groupOwnerId);

            $form->display('id');
            $form->select('group_id', '分组')->options($groupList);
            if (AdminTenantContext::isPlatformAdministrator() && $form->isCreating()) {
                $form->html(
                    '<div class="alert alert-info">请先选择<strong>所属租户的分组</strong>并保存。保存后在列表「CNAME 目标」列查看该租户的边缘域名。</div>',
                    '接入说明'
                );
            } else {
                $cnameAdminId = AdminTenantContext::sessionAdminId();
                if ($form->isEditing() && $form->model()->admin_id) {
                    $cnameAdminId = (int) $form->model()->admin_id;
                }
                $cname = TenantEdgeService::cnameTargetForAdmin($cnameAdminId);
                $cnameHint = AdminTenantContext::isPlatformAdministrator()
                    ? '域名所属租户账号下所有域名共用此 CNAME 目标（改分组迁户后以保存结果为准）'
                    : '本账号下所有域名共用';
                $form->html(
                    '<div class="alert alert-info">请将客户域名 CNAME 到：<code id="redi-cname-form">'.e($cname).'</code> '
                    .'<a href="javascript:;" class="redi-copy-cname" data-copy="'.e($cname).'">复制</a>'
                    .'（'.e($cnameHint).'）</div>',
                    '接入说明'
                );
            }
            $form->text('original_url', '原始域名')->help('客户主域名，如 foo.com（全平台唯一，供边缘节点按 Host 查询）');
            $form->text('skip_url', '跳转链接');
            $form->radio('status', '状态')->options([1=>'启用', 2=>'停用'])->default(2)
                ->help('启用前须将域名 CNAME 到接入目标（或 A 到节点公网 IP）；平台管理员可跳过校验');
            $form->switch('force_https', '强制 HTTPS')->help('证书状态为「已生效」后再打开。开启后节点对 HTTP 请求 301 到 HTTPS');
            if ($form->isCreating() && ! AdminTenantContext::isPlatformAdministrator()) {
                $form->hidden('admin_id')->value(Admin::user()->id);
                $form->hidden('admin_name')->value(Admin::user()->username);
            }

            $form->display('created_at');
            $form->display('updated_at');

            $form->saving(function (Form $form) use (&$previousOriginalUrl) {
                $request = request()->all();
                $sessionAdminId = (int) Admin::user()->id;
                $ownerAdminId = $sessionAdminId;

                if ($form->isEditing()) {
                    $existing = \App\Models\RediAdminDomain::query()->find($form->getKey());
                    if ($existing) {
                        $ownerAdminId = (int) $existing->admin_id;
                        $previousOriginalUrl = $existing->original_url;
                        $form->input('admin_id', $existing->admin_id);
                        $form->input('admin_name', $existing->admin_name);
                    }
                }

                $rawOriginal = $request['original_url'] ?? '';
                $originalUrl = DomainRecordNormalizer::originalUrlIfValid($rawOriginal);
                $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($request['skip_url'] ?? '');
                $groupId = (int) ($request['group_id'] ?? 0);

                if ($skipUrl === '') {
                    throw ValidationException::withMessages([
                        'original_url' => '原始域名与跳转链接不能为空',
                    ]);
                }
                if ($originalUrl === '') {
                    $attempt = DomainRecordNormalizer::normalizeOriginalUrl($rawOriginal);
                    throw ValidationException::withMessages([
                        'original_url' => $attempt === ''
                            ? '原始域名不能为空或含路径'
                            : '原始域名格式无效（请填写主域名主机名，如 foo.com，不要含路径、IP 或非法字符）',
                    ]);
                }

                $groupRow = DB::table('redi_admin_group')->where('id', $groupId)->first();
                if (! $groupRow) {
                    throw ValidationException::withMessages([
                        'group_id' => '分组无效',
                    ]);
                }

                if (AdminTenantContext::isPlatformAdministrator()) {
                    $ownerAdminId = (int) $groupRow->admin_id;
                    $form->input('admin_id', $groupRow->admin_id);
                    $form->input('admin_name', $groupRow->admin_name);
                } elseif ((int) $groupRow->admin_id !== $ownerAdminId) {
                    throw ValidationException::withMessages([
                        'group_id' => '分组无效或不属于域名所属账号',
                    ]);
                }

                $domainId = $form->isEditing() ? (int) $form->getKey() : null;
                if (DomainOriginalUrlGuard::isTakenGlobally($originalUrl, $domainId)) {
                    throw ValidationException::withMessages([
                        'original_url' => '该原始域名已被其他账号或本账号使用（全平台唯一）',
                    ]);
                }

                if ($form->isCreating() && ! AdminTenantContext::isPlatformAdministrator()) {
                    $form->input('admin_id', $sessionAdminId);
                    $form->input('admin_name', Admin::user()->username);
                }

                $ownerAdminName = (string) ($groupRow->admin_name ?? '');
                if (! AdminTenantContext::isPlatformAdministrator() && $form->isCreating()) {
                    $ownerAdminName = (string) Admin::user()->username;
                } elseif ($form->isEditing() && isset($existing)) {
                    $ownerAdminName = (string) $existing->admin_name;
                }

                $form->model()->forceFill([
                    'admin_id' => $ownerAdminId,
                    'admin_name' => $ownerAdminName,
                ]);

                $form->input('original_url', $originalUrl);
                $form->input('skip_url', $skipUrl);

                $wantsForceHttps = filter_var($request['force_https'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($wantsForceHttps) {
                    $domainId = $form->isEditing() ? (int) $form->getKey() : null;
                    $certActive = false;
                    if ($domainId) {
                        $certActive = RediDomainCert::query()
                            ->where('domain_id', $domainId)
                            ->where('status', RediDomainCert::STATUS_ACTIVE)
                            ->exists();
                    }
                    if (! $certActive) {
                        throw ValidationException::withMessages([
                            'force_https' => '证书尚未生效，不能开启强制 HTTPS。请先申请证书并等到状态为「已生效」。',
                        ]);
                    }
                }

                $newStatus = (int) ($request['status'] ?? 2);
                if ($newStatus === 1) {
                    DomainDnsPointingGuard::assertCanEnable($ownerAdminId, $originalUrl);
                }

                TenantEdgeService::ensureForAdmin($ownerAdminId);
            });

            $form->saved(function (Form $form) use (&$previousOriginalUrl) {
                $domain = \App\Models\RediAdminDomain::query()->find($form->getKey());
                if (! $domain) {
                    return;
                }
                TenantEdgeService::ensureForAdmin((int) $domain->admin_id);

                RediDomainCert::query()
                    ->where('domain_id', $domain->id)
                    ->update([
                        'domain_name' => $domain->original_url,
                        'admin_id' => $domain->admin_id,
                    ]);

                if (! $form->isEditing()) {
                    return;
                }

                $oldUrl = (string) ($previousOriginalUrl ?? '');
                $newUrl = $domain->original_url;
                if ($oldUrl !== '' && $oldUrl !== $newUrl) {
                    if ($oldUrl !== '') {
                        RediAcmeChallenge::query()->where('domain_name', $oldUrl)->delete();
                        RediAcmeChallengeNodeSync::query()->where('domain_name', $oldUrl)->delete();
                    }
                    RediAcmeChallengeNodeSync::query()->where('domain_name', $newUrl)->delete();
                    if ((int) $domain->status === 1) {
                        IssueDomainCertificateJob::dispatch((int) $domain->id)
                            ->delay(now()->addSeconds(15));
                    }
                }
            });
        });
    }
}
