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
use App\Admin\Actions\Modal\BatchFilterDomainModal;
use App\Models\RediDomainCert;
use App\Services\DomainOriginalUrlGuard;
use App\Services\DomainCertificateAdminSync;
use App\Services\DomainRecordNormalizer;
use App\Services\CnamePrecheckService;
use App\Services\TenantEdgeService;
use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;
use App\Admin\Actions\Grid\ApplyCertificate;
use App\Admin\Actions\Grid\BatchApplyCertificate;
use App\Admin\Actions\Grid\BatchDisableDomains;
use App\Admin\Actions\Grid\BatchEditDomains;
use App\Admin\Actions\Grid\BatchEnableDomains;
use App\Admin\Actions\Grid\BatchDeleteDomains;
use App\Admin\Actions\Grid\BatchRedeployCertificate;
use App\Admin\Actions\Grid\RedeployCertificate;
use App\Jobs\IssueDomainCertificateJob;
use App\Services\DomainListCsvExporter;
use Illuminate\Http\Request;
use App\Services\AcmeChallengeCleanup;
use Illuminate\Validation\ValidationException;


class RediAdminDomainController extends AdminController
{
    protected $translation = 'redi-admin-domain';

    public function title()
    {
        return admin_trans_label('title');
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
                $tools->append(new BatchFilterDomainModal());
                $exportQuery = http_build_query(array_filter(
                    request()->only(['group_id', 'original_url', 'skip_url', 'status', 'cert_filter']),
                    function ($v) {
                        return $v !== null && $v !== '';
                    }
                ));
                $exportUrl = admin_url('domain/export'.($exportQuery !== '' ? '?'.$exportQuery : ''));
                $tools->append(
                    '<a href="'.e($exportUrl).'" class="btn btn-default btn-sm" style="margin-left:6px"><i class="feather icon-download"></i> '
                    .e(admin_trans_label('export_csv')).'</a>'
                );
            });

            $grid->batchActions(function ($batch) {
                $batch->add(new BatchEnableDomains());
                $batch->add(new BatchDisableDomains());
                $batch->add(new BatchEditDomains());
                $batch->add(new BatchApplyCertificate());
                $batch->add(new BatchRedeployCertificate());
                $batch->add(new BatchDeleteDomains());
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
            $grid->column('group_id', admin_trans_field('group_id'))->display(function () {
                $name = $this->group?->name;

                return e(OpsDisplaySanitizer::field($name, admin_trans_label('group_missing'), 120));
            });
            $grid->column('original_url', admin_trans_field('original_url'))->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 253));
            });
            $grid->column('cname_target', admin_trans_field('cname_target'))->display(function () {
                $target = TenantEdgeService::cnameTargetForAdmin((int) $this->admin_id);
                if ($target === '' || TenantEdgeService::isPlaceholderCnameTarget($target)) {
                    return $target;
                }
                $safe = OpsDisplaySanitizer::field(strtolower($target), '', 253);

                return '<span class="redi-cname-target">'.e($safe).'</span> '
                    .'<a href="javascript:;" class="redi-copy-cname" data-copy="'.e($safe).'">'
                    .e(admin_trans_label('copy')).'</a>';
            });
            $grid->column('cname_ok', admin_trans_field('cname_ok'))->display(function () {
                $edgeFqdn = TenantEdgeService::cnameTargetForAdmin((int) $this->admin_id);
                if ($edgeFqdn === '' || TenantEdgeService::isPlaceholderCnameTarget($edgeFqdn)) {
                    return '—';
                }
                $ok = CnamePrecheckService::pointsToEdgeCached($this->original_url, $edgeFqdn);

                return $ok ? admin_trans_label('dns_pointed') : admin_trans_label('dns_not_pointed');
            });
            $grid->column('skip_url', admin_trans_field('skip_url'))->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 500));
            });
            $grid->column('cert_status', admin_trans_field('cert_status'))->display(function () {
                $cert = $this->certificate;
                if (! $cert) {
                    return admin_trans_option(RediDomainCert::STATUS_NONE, 'cert_status');
                }
                $label = e($cert->statusLabel());
                if ($cert->storedCredentialsUnreadable()) {
                    $label .= '<br><span class="text-danger">'.e(admin_trans_label('cert_decrypt_fail')).'</span>';
                } elseif ($cert->last_error) {
                    $label .= '<br><span class="text-danger">'.e(admin_trans_label('cert_last_error')).' '
                        .e(OpsDisplaySanitizer::field($cert->last_error, '—', 500)).'</span>';
                }
                if ($cert->expires_at) {
                    $exp = $cert->expires_at;
                    $label .= '<br><span class="text-muted">'.e(admin_trans_label('expires')).' '.e($exp->format('Y-m-d')).'</span>';
                    if ($exp->isPast()) {
                        $label .= ' <span class="text-danger">'.e(admin_trans_label('expired')).'</span>';
                    } elseif ($exp->lte(now()->addDays(14))) {
                        $label .= ' <span class="text-warning">'.e(admin_trans_label('expiring_soon')).'</span>';
                    }
                }

                return $label;
            });
            $grid->column('skip_num', admin_trans_field('skip_num'));
            $grid->column('status', admin_trans_field('status'))->using(admin_trans('redi-admin-domain.options.status'));
            $grid->column('admin_name', admin_trans_field('admin_name'))->display(function ($value) {
                return e(OpsDisplaySanitizer::field($value, '—', 120));
            });
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();

            Admin::style('.redi-cname-target{text-transform:none !important;}');
            $copyCnameMsg = json_encode(admin_trans_label('copy_cname_success'), JSON_UNESCAPED_UNICODE);
            Admin::script(<<<JS
Dcat.ready(function () {
  var copyMsg = {$copyCnameMsg};
  $(document).off('click', '.redi-copy-cname').on('click', '.redi-copy-cname', function () {
    var text = $(this).data('copy') || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { Dcat.success(copyMsg); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      Dcat.success(copyMsg);
    }
  });
});
JS
            );

            $grid->filter(function (Grid\Filter $filter) {
                $filter->panel();

                $filter->equal('group_id', admin_trans_field('group_id_form'))->select(AdminTenantContext::groupSelectOptions())->width(3);

                $filter->like('original_url', admin_trans_field('original_url'))->width(4);
                $filter->like('skip_url', admin_trans_field('skip_url'))->width(4);
                $filter->equal('status', admin_trans_field('status'))->select(admin_trans('redi-admin-domain.options.status'))->width(3);

                $filter->where('cert_filter', function ($query) {
                    \App\Models\RediAdminDomain::applyCertificateListFilter($query, $this->input);
                }, admin_trans_field('cert_status'))->select(admin_trans('redi-admin-domain.options.cert_filter'))->width(3);

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
            $form->select('group_id', admin_trans_field('group_id_form'))->options($groupList);
            if (AdminTenantContext::isPlatformAdministrator() && $form->isCreating()) {
                $form->html(
                    '<div class="alert alert-info">'.admin_trans_label('onboarding_admin_create').'</div>',
                    admin_trans_label('onboarding')
                );
            } else {
                $cnameAdminId = AdminTenantContext::sessionAdminId();
                if ($form->isEditing() && $form->model()->admin_id) {
                    $cnameAdminId = (int) $form->model()->admin_id;
                }
                if ($cnameAdminId > 0) {
                    TenantEdgeService::ensureForAdmin($cnameAdminId);
                }
                $cname = strtolower(TenantEdgeService::cnameTargetForAdmin($cnameAdminId));
                $cnameHint = AdminTenantContext::isPlatformAdministrator()
                    ? admin_trans_label('cname_hint_admin')
                    : admin_trans_label('cname_hint_tenant');
                Admin::style('.redi-cname-target{text-transform:none !important;}');
                $form->html(
                    '<div class="alert alert-info">'.e(admin_trans_label('cname_instruction')).'<code id="redi-cname-form" class="redi-cname-target">'.e($cname).'</code> '
                    .'<a href="javascript:;" class="redi-copy-cname" data-copy="'.e($cname).'">'.e(admin_trans_label('copy')).'</a>'
                    .'（'.e($cnameHint).'）</div>',
                    admin_trans_label('onboarding')
                );
            }
            $form->text('original_url', admin_trans_field('original_url'))->help(admin_trans('redi-admin-domain.help.original_url'));
            $form->text('skip_url', admin_trans_field('skip_url_form'));
            $form->radio('status', admin_trans_field('status'))->options(admin_trans('redi-admin-domain.options.status'))->default(2)
                ->help(admin_trans('redi-admin-domain.help.status'));
            $form->switch('force_https', admin_trans_field('force_https'))->help(admin_trans('redi-admin-domain.help.force_https'));
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
                        'original_url' => admin_trans('redi-admin-domain.validation.original_skip_required'),
                    ]);
                }
                if ($originalUrl === '') {
                    $attempt = DomainRecordNormalizer::normalizeOriginalUrl($rawOriginal);
                    throw ValidationException::withMessages([
                        'original_url' => $attempt === ''
                            ? admin_trans('redi-admin-domain.validation.original_empty')
                            : admin_trans('redi-admin-domain.validation.original_invalid'),
                    ]);
                }

                $groupRow = DB::table('redi_admin_group')->where('id', $groupId)->first();
                if (! $groupRow) {
                    throw ValidationException::withMessages([
                        'group_id' => admin_trans('redi-admin-domain.validation.group_invalid'),
                    ]);
                }

                if (AdminTenantContext::isPlatformAdministrator()) {
                    $ownerAdminId = (int) $groupRow->admin_id;
                    $form->input('admin_id', $groupRow->admin_id);
                    $form->input('admin_name', $groupRow->admin_name);
                } elseif ((int) $groupRow->admin_id !== $ownerAdminId) {
                    throw ValidationException::withMessages([
                        'group_id' => admin_trans('redi-admin-domain.validation.group_wrong_owner'),
                    ]);
                }

                $domainId = $form->isEditing() ? (int) $form->getKey() : null;
                if (DomainOriginalUrlGuard::isTakenGlobally($originalUrl, $domainId)) {
                    throw ValidationException::withMessages([
                        'original_url' => admin_trans('redi-admin-domain.validation.original_taken'),
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
                            'force_https' => admin_trans('redi-admin-domain.validation.force_https'),
                        ]);
                    }
                }

                TenantEdgeService::ensureForAdmin($ownerAdminId);
            });

            $form->saved(function (Form $form) use (&$previousOriginalUrl) {
                $domain = \App\Models\RediAdminDomain::query()->find($form->getKey());
                if (! $domain) {
                    return;
                }
                TenantEdgeService::ensureForAdmin((int) $domain->admin_id);

                DomainCertificateAdminSync::realignCertsForDomain($domain);

                if (! $form->isEditing()) {
                    return;
                }

                $oldUrl = (string) ($previousOriginalUrl ?? '');
                $newUrl = $domain->original_url;
                if ($oldUrl !== '' && $oldUrl !== $newUrl) {
                    AcmeChallengeCleanup::purgeForHosts([$oldUrl, $newUrl]);
                    if ((int) $domain->status === 1) {
                        IssueDomainCertificateJob::dispatch((int) $domain->id)
                            ->delay(now()->addSeconds(15));
                    }
                }
            });
        });
    }
}
