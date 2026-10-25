<?php

namespace App\Admin\Forms;

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

        DB::table('redi_admin_domain')
            ->where('group_id', $groupId)
            ->where('admin_id', (int) $group->admin_id)
            ->update(['skip_url' => $skipUrl]);

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
