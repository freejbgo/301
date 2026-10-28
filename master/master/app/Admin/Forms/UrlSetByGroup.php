<?php

namespace App\Admin\Forms;

use App\Admin\Concerns\RecordsAdminBatchAudit;
use App\Services\DomainBatchMutator;
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
        $skipUrlRaw = (string) ($input['skip_url'] ?? '');

        if ($groupId <= 0) {
            return $this->response()->error(__('redi-admin-group.url_set.error_group_id'));
        }

        $group = DB::table('redi_admin_group')->where('id', $groupId)->first();
        if (! $group) {
            return $this->response()->error(__('redi-admin-group.url_set.error_group'));
        }
        if (! AdminTenantContext::isPlatformAdministrator() && (int) $group->admin_id !== $adminId) {
            return $this->response()->error(__('redi-admin-group.url_set.error_forbidden'));
        }

        $mutator = new DomainBatchMutator();
        $result = $mutator->setSkipUrlForGroupOwned(
            $groupId,
            $skipUrlRaw,
            $adminId,
            AdminTenantContext::isPlatformAdministrator()
        );

        if ($result['updated'] === 0 && $result['skipped'] === 0 && $result['errors'] !== []) {
            $msg = implode("\n", array_slice($result['errors'], 0, 6));

            return $this->response()->error($msg !== '' ? $msg : __('redi-admin-group.url_set.error_skip_url'));
        }

        if ($result['updated'] > 0) {
            $msg = __('redi-admin-group.url_set.success');
        } elseif ($result['skipped'] > 0) {
            $msg = __('redi-admin-group.url_set.success_all_skipped', ['count' => $result['skipped']]);
        } else {
            $msg = __('redi-admin-group.url_set.success');
        }

        if ($result['skipped'] > 0 && $result['updated'] > 0) {
            $msg .= __('redi-admin-domain.actions.batch_mutator.skipped_suffix', ['count' => $result['skipped']]);
        }
        if ($result['errors'] !== []) {
            $msg .= "\n".__('redi-admin-domain.actions.batch_mutator.partial_errors', [
                'count' => count($result['errors']),
            ]);
            $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 5));
        }

        $this->auditGroupUrlSet($groupId, $result);

        return $this
            ->response()
            ->success($msg)
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
