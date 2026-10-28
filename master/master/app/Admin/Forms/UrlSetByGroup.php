<?php

namespace App\Admin\Forms;

use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\DomainRecordNormalizer;
use App\Support\AdminTenantContext;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\DB;

class UrlSetByGroup extends Form implements LazyRenderable
{
    use LazyWidget;
    use RecordsAdminBatchAudit;

    public function handle(array $input)
    {
        $groupId = (int) ($input['id'] ?? 0);
        $adminId = (int) Admin::user()->id;
        $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($input['skip_url'] ?? '');

        if ($groupId <= 0) {
            return $this->response()->error(__('redi-admin-group.url_set.error_group_id'));
        }
        if ($skipUrl === '') {
            return $this->response()->error(__('redi-admin-group.url_set.error_skip_url'));
        }

        $group = DB::table('redi_admin_group')->where('id', $groupId)->first();
        if (! $group) {
            return $this->response()->error(__('redi-admin-group.url_set.error_group'));
        }
        if (! AdminTenantContext::isPlatformAdministrator() && (int) $group->admin_id !== $adminId) {
            return $this->response()->error(__('redi-admin-group.url_set.error_forbidden'));
        }

        $ownerId = (int) $group->admin_id;
        $skipped = (int) DB::table('redi_admin_domain')
            ->where('group_id', $groupId)
            ->where('admin_id', $ownerId)
            ->where('skip_url', $skipUrl)
            ->count();

        $updated = (int) DB::table('redi_admin_domain')
            ->where('group_id', $groupId)
            ->where('admin_id', $ownerId)
            ->where('skip_url', '!=', $skipUrl)
            ->update([
                'skip_url' => $skipUrl,
                'updated_at' => now(),
            ]);

        $this->auditGroupUrlSet($groupId, $updated, $skipped);

        return $this
            ->response()
            ->success(__('redi-admin-group.url_set.success'))
            ->refresh();
    }

    public function form()
    {
        $this->confirm(
            __('redi-admin-group.url_set.confirm_title'),
            __('redi-admin-group.url_set.confirm_content')
        );

        $this->text('skip_url', __('redi-admin-group.url_set.field_skip_url'))->required();
        $this->hidden('id');
    }

    public function default()
    {
        return [
            'id' => $this->payload['id'] ?? 0,
        ];
    }
}
