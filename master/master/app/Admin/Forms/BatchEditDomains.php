<?php

namespace App\Admin\Forms;

use App\Services\DomainBatchMutator;
use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;

class BatchEditDomains extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $rawIds = $input['ids'] ?? '';
        $domainIds = array_values(array_filter(array_map('intval', explode(',', (string) $rawIds)), static fn (int $id): bool => $id > 0));

        if ($domainIds === []) {
            return $this->response()->error(__('redi-admin-domain.batch_mutator.errors.no_selection'));
        }

        $skipUrl = isset($input['skip_url']) ? trim((string) $input['skip_url']) : '';
        $groupRaw = $input['group_id'] ?? '';
        $groupId = $groupRaw === '' || $groupRaw === null ? null : (int) $groupRaw;
        $forceHttps = (string) ($input['force_https'] ?? '');

        $mutator = new DomainBatchMutator();
        $result = $mutator->applyEditsOwned(
            $domainIds,
            (int) Admin::user()->id,
            $skipUrl !== '' ? $skipUrl : null,
            $groupId,
            $forceHttps
        );

        if ($result['updated'] === 0 && $result['errors'] === []) {
            return $this->response()->error(__('redi-admin-domain.batch_mutator.errors.nothing_changed'));
        }

        if ($result['updated'] === 0) {
            $msg = __('redi-admin-domain.actions.batch_edit.error_all');
            $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 8));

            return $this->response()->error($msg);
        }

        $summary = __('redi-admin-domain.actions.batch_edit.success', ['count' => $result['updated']]);
        if ($result['skipped'] > 0) {
            $summary .= __('redi-admin-domain.actions.batch_mutator.skipped_suffix', ['count' => $result['skipped']]);
        }
        if ($result['errors'] !== []) {
            $summary .= "\n".__('redi-admin-domain.actions.batch_mutator.partial_errors', [
                'count' => count($result['errors']),
            ]);
        }

        $summary = BatchSelectionLimiter::appendTruncationNotice($summary, (int) ($result['truncated'] ?? 0));

        return $this->response()->success($summary)->refresh();
    }

    public function form()
    {
        $this->hidden('ids')->attribute('id', 'batch-edit-domain-ids');

        $this->text('skip_url', __('redi-admin-domain.batch_edit.fields.skip_url'))
            ->help(__('redi-admin-domain.batch_edit.help.skip_url'));

        $groupOwnerId = AdminTenantContext::isPlatformAdministrator() ? null : AdminTenantContext::sessionAdminId();
        $this->select('group_id', __('redi-admin-domain.batch_edit.fields.group_id'))
            ->options(AdminTenantContext::groupSelectOptions($groupOwnerId))
            ->help(__('redi-admin-domain.batch_edit.help.group_id'));

        $this->radio('force_https', __('redi-admin-domain.batch_edit.fields.force_https'))
            ->options([
                '' => __('redi-admin-domain.batch_edit.options.force_https_keep'),
                '1' => __('redi-admin-domain.batch_edit.options.force_https_on'),
                '0' => __('redi-admin-domain.batch_edit.options.force_https_off'),
            ])
            ->default('')
            ->help(__('redi-admin-domain.batch_edit.help.force_https'));
    }
}
