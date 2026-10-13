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
use App\Models\RediAdminGroup;
use App\Models\RediDomainCert;
use App\Services\DomainOriginalUrlGuard;
use App\Services\DomainRecordNormalizer;
use App\Services\CnamePrecheckService;
use App\Services\TenantEdgeService;
use App\Admin\Actions\Grid\ApplyCertificate;
use App\Admin\Actions\Grid\BatchApplyCertificate;
use Illuminate\Validation\ValidationException;


class RediAdminDomainController extends AdminController
{
    public function title()
    {
        return '域名管理';
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
            });

            $grid->batchActions(function ($batch) {
                $batch->add(new BatchApplyCertificate());
            });

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->append(new ApplyCertificate());
            });
            
            $grid->model()->where('admin_id', '=', Admin::user()->id);
            $grid->model()->with(['group', 'certificate']);

            $grid->column('id')->sortable();
            $grid->column('group_id', '分组名称')->display(function () {
                return $this->group?->name ?? '（分组不存在）';
            });
            $grid->column('original_url', '原始域名');
            $grid->column('cname_target', 'CNAME 目标')->display(function () {
                $target = TenantEdgeService::cnameTargetForAdmin((int) Admin::user()->id);
                if ($target === '' || strncmp($target, '（', 3) === 0) {
                    return $target;
                }
                return e($target).' <a href="javascript:;" class="redi-copy-cname" data-copy="'.e($target).'">复制</a>';
            });
            $grid->column('cname_ok', '解析预检')->display(function () {
                $edgeFqdn = TenantEdgeService::cnameTargetForAdmin((int) Admin::user()->id);
                if ($edgeFqdn === '' || strncmp($edgeFqdn, '（', 3) === 0) {
                    return '—';
                }
                $ok = CnamePrecheckService::pointsToEdgeCached($this->original_url, $edgeFqdn);

                return $ok ? '已指向' : '未检测到';
            });
            $grid->column('skip_url', '跳转地址');
            $grid->column('cert_status', '证书')->display(function () {
                $cert = $this->certificate;
                if (! $cert) {
                    return '未申请';
                }
                $label = e($cert->statusLabel());
                if ($cert->storedCredentialsUnreadable()) {
                    $label .= '<br><span class="text-danger">证书密文无法解密（检查 APP_KEY 是否变更）</span>';
                } elseif ($cert->last_error) {
                    $label .= '<br><span class="text-danger">'.e($cert->last_error).'</span>';
                }

                return $label;
            });
            $grid->column('skip_num', '跳转次数');
            $grid->column('status', '状态')->using([1 => "启用", 2 => "停用"]);
            $grid->column('admin_name', '添加人');
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

                //获取分类列表
                $groupList = [];
                $groupRet = DB::table("redi_admin_group")->where('admin_id', Admin::user()->id)->get(['id', 'name']);
                foreach($groupRet as $val){
                    $groupList[$val->id] = $val->name;
                }
                $filter->equal('group_id', '分组')->select($groupList)->width(3);

                $filter->like('original_url', '原始域名')->width(4);
                $filter->like('skip_url', '跳转地址')->width(4);
                $filter->equal('status', '状态')->select([1 => '启用', 2 => '停用'])->width(3);

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
            $groupList = [];
            $groupRet = DB::table("redi_admin_group")->where('admin_id', Admin::user()->id)->where('status', 1)->get(['id', 'name']);
            foreach($groupRet as $val){
                $groupList[$val->id] = $val->name;
            }

            $form->display('id');
            $form->select('group_id', '分组')->options($groupList);
            $cname = TenantEdgeService::cnameTargetForAdmin((int) Admin::user()->id);
            $form->html(
                '<div class="alert alert-info">请将客户域名 CNAME 到：<code id="redi-cname-form">'.e($cname).'</code> '
                .'<a href="javascript:;" class="redi-copy-cname" data-copy="'.e($cname).'">复制</a>'
                .'（本账号下所有域名共用）</div>',
                '接入说明'
            );
            $form->text('original_url', '原始域名')->help('客户主域名，如 foo.com（全平台唯一，供边缘节点按 Host 查询）');
            $form->text('skip_url', '跳转链接');
            $form->radio('status', '状态')->options([1=>'启用', 2=>'停用'])->default(1);
            $form->switch('force_https', '强制 HTTPS')->help('证书状态为「已生效」后再打开。开启后节点对 HTTP 请求 301 到 HTTPS');
            if ($form->isCreating()) {
                $form->hidden('admin_id')->value(Admin::user()->id);
                $form->hidden('admin_name')->value(Admin::user()->username);
            }

            $form->display('created_at');
            $form->display('updated_at');

            $form->saving(function (Form $form) {
                $request = request()->all();
                $adminId = (int) Admin::user()->id;

                $originalUrl = DomainRecordNormalizer::normalizeOriginalUrl($request['original_url'] ?? '');
                $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($request['skip_url'] ?? '');
                $groupId = (int) ($request['group_id'] ?? 0);

                if ($originalUrl === '' || $skipUrl === '') {
                    throw ValidationException::withMessages([
                        'original_url' => '原始域名与跳转链接不能为空',
                    ]);
                }

                $groupOk = RediAdminGroup::query()
                    ->where('admin_id', $adminId)
                    ->where('id', $groupId)
                    ->exists();

                if (! $groupOk) {
                    throw ValidationException::withMessages([
                        'group_id' => '分组无效或不属于当前账号',
                    ]);
                }

                $domainId = $form->isEditing() ? (int) $form->getKey() : null;
                if (DomainOriginalUrlGuard::isTakenGlobally($originalUrl, $domainId)) {
                    throw ValidationException::withMessages([
                        'original_url' => '该原始域名已被其他账号或本账号使用（全平台唯一）',
                    ]);
                }

                if ($form->isEditing()) {
                    $existing = \App\Models\RediAdminDomain::query()->find($form->getKey());
                    if ($existing) {
                        $form->input('admin_id', $existing->admin_id);
                        $form->input('admin_name', $existing->admin_name);
                    }
                }

                if ($form->isCreating()) {
                    $form->input('admin_id', $adminId);
                    $form->input('admin_name', Admin::user()->username);
                }

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

                TenantEdgeService::ensureForAdmin($adminId);
            });

            $form->saved(function (Form $form) {
                $adminId = (int) Admin::user()->id;
                TenantEdgeService::ensureForAdmin($adminId);

                if ($form->isEditing()) {
                    $domain = \App\Models\RediAdminDomain::query()->find($form->getKey());
                    if ($domain) {
                        RediDomainCert::query()
                            ->where('domain_id', $domain->id)
                            ->update(['domain_name' => $domain->original_url]);
                    }
                }
            });
        });
    }
}
