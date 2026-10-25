<?php

namespace App\Admin\Forms;

use App\Services\DomainBatchCreator;
use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;
use Dcat\Admin\Admin;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\DB;

class BatchCreateDomains extends Form
{
    public function handle(array $input)
    {
        $user = Admin::user();
        $groupId = (int) ($input['group_id'] ?? 0);
        $group = DB::table('redi_admin_group')->where('id', $groupId)->first();
        if (! $group) {
            return $this->response()->error(__('redi-batch-domains.messages.group_invalid'));
        }
        if (! AdminTenantContext::isPlatformAdministrator() && (int) $group->admin_id !== (int) $user->id) {
            return $this->response()->error(__('redi-batch-domains.messages.group_wrong_owner'));
        }

        $creator = new DomainBatchCreator();

        $result = $creator->createFromTextLines(
            (int) $group->admin_id,
            (string) $group->admin_name,
            $groupId,
            (int) ($input['status'] ?? 2),
            (string) ($input['lines'] ?? ''),
            $input['default_skip_url'] ?? null
        );

        $formatErrors = function (array $errors, int $limit): string {
            $lines = array_map(
                fn (string $line) => OpsDisplaySanitizer::field($line, '—', 500),
                array_slice($errors, 0, $limit)
            );

            return implode("\n", $lines);
        };

        if ($result['imported'] === 0 && $result['skipped'] === 0) {
            $message = $result['errors'] !== []
                ? $formatErrors($result['errors'], 15)
                : __('redi-batch-domains.messages.nothing_imported');

            return $this->response()->error($message);
        }

        $summary = __('redi-batch-domains.messages.summary', [
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);
        if ($result['errors'] !== []) {
            $summary .= "\n".__('redi-batch-domains.messages.partial_errors', [
                'errors' => $formatErrors($result['errors'], 10),
            ]);
        }

        return $this->response()->success($summary)->refresh();
    }

    public function form()
    {
        $groupList = AdminTenantContext::groupSelectOptions(
            AdminTenantContext::isPlatformAdministrator() ? null : AdminTenantContext::sessionAdminId()
        );

        $max = DomainBatchCreator::MAX_LINES_PER_BATCH;

        $this->select('group_id', __('redi-batch-domains.fields.group_id'))->options($groupList)->required();
        $this->radio('status', __('redi-batch-domains.fields.status'))
            ->options(trans('redi-batch-domains.options.status'))
            ->default(2)
            ->help(__('redi-batch-domains.help.status'));
        $this->text('default_skip_url', __('redi-batch-domains.fields.default_skip_url'))
            ->help(__('redi-batch-domains.help.default_skip_url'));

        $placeholder = __('redi-batch-domains.placeholder', ['max' => $max]);

        $this->textarea('lines', __('redi-batch-domains.fields.lines'))
            ->rows(14)
            ->required()
            ->placeholder($placeholder)
            ->help(__('redi-batch-domains.help.lines', ['max' => $max]));
    }
}
