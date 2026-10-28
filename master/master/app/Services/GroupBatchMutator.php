<?php

namespace App\Services;

use App\Models\RediAdminGroup;
use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;

class GroupBatchMutator
{
    public const MAX_IDS_PER_BATCH = 200;

    /**
     * @param  array<int, int|string>  $groupIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function setStatusOwned(
        array $groupIds,
        int $status,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        if (! in_array($status, [1, 2], true)) {
            $result['errors'][] = __('redi-admin-group.batch_mutator.errors.status_invalid');

            return $result;
        }

        $ids = $this->normalizeOwnedIds($groupIds, $sessionAdminId, $isPlatformAdministrator);
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-group.batch_mutator.errors.no_groups');

            return $result;
        }

        $groups = RediAdminGroup::query()->whereIn('id', $ids)->orderBy('id')->get();
        foreach ($groups as $group) {
            if ((int) $group->status === $status) {
                $result['skipped']++;

                continue;
            }
            $group->status = $status;
            try {
                $group->save();
                $result['updated']++;
            } catch (\Throwable $e) {
                $label = OpsDisplaySanitizer::field($group->name, '—', 120);
                $result['errors'][] = __('redi-admin-group.batch_mutator.errors.row_save', [
                    'label' => $label,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $groupIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function deleteOwned(
        array $groupIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        $ids = $this->normalizeOwnedIds($groupIds, $sessionAdminId, $isPlatformAdministrator);
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-group.batch_mutator.errors.no_groups');

            return $result;
        }

        $groups = RediAdminGroup::query()->whereIn('id', $ids)->orderBy('id')->get();
        foreach ($groups as $group) {
            $label = OpsDisplaySanitizer::field($group->name, '—', 120);
            try {
                $group->delete();
                $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = __('redi-admin-group.batch_mutator.errors.row_delete', [
                    'label' => $label,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $groupIds
     * @return array<int, int>
     */
    protected function normalizeOwnedIds(
        array $groupIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator
    ): array {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
        if ($groupIds === []) {
            return [];
        }
        if (count($groupIds) > self::MAX_IDS_PER_BATCH) {
            $groupIds = array_slice($groupIds, 0, self::MAX_IDS_PER_BATCH);
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();
        $query = RediAdminGroup::query()->whereIn('id', $groupIds);
        if (! $isPlatformAdministrator) {
            $query->where('admin_id', $sessionAdminId);
        }

        return $query->orderBy('id')->pluck('id')->all();
    }
}
