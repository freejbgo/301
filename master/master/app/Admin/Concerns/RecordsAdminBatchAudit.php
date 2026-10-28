<?php

namespace App\Admin\Concerns;

use App\Support\AdminBatchOperationAudit;
use Dcat\Admin\Admin;

trait RecordsAdminBatchAudit
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $context
     */
    protected function auditAdminBatch(string $module, string $action, array $metrics, array $context = []): void
    {
        $adminId = (int) (Admin::user()?->id ?? 0);
        AdminBatchOperationAudit::record($module, $action, $adminId, $metrics, $context);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, int|string>  $selectedKeys
     * @param  array<string, mixed>  $context
     */
    protected function auditFromMutator(string $module, string $action, array $result, array $selectedKeys = [], array $context = []): void
    {
        if ((int) ($result['updated'] ?? 0) <= 0) {
            return;
        }

        $context['mode'] = $context['mode'] ?? 'grid_selection';
        $context['selection'] = AdminBatchOperationAudit::idSample($selectedKeys);

        $this->auditAdminBatch($module, $action, AdminBatchOperationAudit::metricsFromMutator($result), $context);
    }

    /**
     * @param  array{dispatched?: int, truncated?: int}  $dispatch
     * @param  array<int, int|string>  $selectedKeys
     */
    protected function auditCertDispatch(string $action, array $dispatch, array $selectedKeys): void
    {
        $dispatched = (int) ($dispatch['dispatched'] ?? 0);
        if ($dispatched <= 0) {
            return;
        }

        $this->auditAdminBatch('domain', $action, [
            'updated' => $dispatched,
            'truncated' => (int) ($dispatch['truncated'] ?? 0),
            'skipped' => 0,
            'error_count' => 0,
        ], [
            'mode' => 'grid_selection',
            'selection' => AdminBatchOperationAudit::idSample($selectedKeys),
        ]);
    }

    /**
     * @param  array{queued?: int, skipped?: int, truncated?: int}  $redeploy
     * @param  array<int, int|string>  $selectedKeys
     */
    protected function auditCertRedeploy(array $redeploy, array $selectedKeys): void
    {
        $queued = (int) ($redeploy['queued'] ?? 0);
        if ($queued <= 0) {
            return;
        }

        $this->auditAdminBatch('domain', 'batch_redeploy_cert', [
            'updated' => $queued,
            'skipped' => (int) ($redeploy['skipped'] ?? 0),
            'truncated' => (int) ($redeploy['truncated'] ?? 0),
            'error_count' => 0,
        ], [
            'mode' => 'grid_selection',
            'selection' => AdminBatchOperationAudit::idSample($selectedKeys),
        ]);
    }

    /**
     * @param  array{imported: int, skipped: int, errors: string[]}  $result
     */
    protected function auditBatchCreateDomains(array $result, int $groupId, int $status): void
    {
        $imported = (int) ($result['imported'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        if ($imported <= 0 && $skipped <= 0) {
            return;
        }

        $this->auditAdminBatch('domain', 'batch_create', [
            'updated' => $imported,
            'skipped' => $skipped,
            'truncated' => 0,
            'error_count' => count($result['errors'] ?? []),
        ], [
            'mode' => 'batch_create',
            'group_id' => $groupId,
            'status' => $status,
        ]);
    }

    protected function auditGroupUrlSet(int $groupId, int $updated, int $skipped): void
    {
        $this->auditAdminBatch('group', 'url_set_by_group', [
            'updated' => $updated,
            'skipped' => $skipped,
            'truncated' => 0,
            'error_count' => 0,
        ], [
            'mode' => 'group_row_action',
            'group_id' => $groupId,
        ]);
    }
}
