<?php

namespace App\Services;

use App\Models\RediAdminGroup;
use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;
use App\Support\OpsDisplaySanitizer;

class GroupBatchMutator
{
    public const MAX_IDS_PER_BATCH = 200;

    /**
     * @param  array<int, int|string>  $groupIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function setStatusOwned(
        array $groupIds,
        int $status,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        if (! in_array($status, [1, 2], true)) {
            $result['errors'][] = __('redi-admin-group.batch_mutator.errors.status_invalid');

            return $result;
        }

        $normalized = $this->normalizeOwnedIds($groupIds, $sessionAdminId, $isPlatformAdministrator);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
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
            } catch (\Illuminate\Validation\ValidationException $e) {
                $label = OpsDisplaySanitizer::field($group->name, '—', 120);
                $result['errors'][] = __('redi-admin-group.batch_mutator.errors.row_save', [
                    'label' => $label,
                    'reason' => $e->validator->errors()->first() ?: __('redi-admin-group.batch_mutator.errors.save_failed'),
                ]);
            } catch (\Throwable $e) {
                $label = OpsDisplaySanitizer::field($group->name, '—', 120);
                $result['errors'][] = __('redi-admin-group.batch_mutator.errors.row_save', [
                    'label' => $label,
                    'reason' => __('redi-admin-group.batch_mutator.errors.save_failed'),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $groupIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function deleteOwned(
        array $groupIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        $normalized = $this->normalizeOwnedIds($groupIds, $sessionAdminId, $isPlatformAdministrator);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
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
            } catch (\Illuminate\Validation\ValidationException $e) {
                $result['errors'][] = __('redi-admin-group.batch_mutator.errors.row_delete', [
                    'label' => $label,
                    'reason' => $e->validator->errors()->first() ?: __('redi-admin-group.batch_mutator.errors.save_failed'),
                ]);
            } catch (\Throwable $e) {
                $result['errors'][] = __('redi-admin-group.batch_mutator.errors.row_delete', [
                    'label' => $label,
                    'reason' => __('redi-admin-group.batch_mutator.errors.save_failed'),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $groupIds
     * @return array{ids: list<int>, truncated: int}
     */
    protected function normalizeOwnedIds(
        array $groupIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator
    ): array {
        $capped = BatchSelectionLimiter::intIdsCapped($groupIds, self::MAX_IDS_PER_BATCH);
        if ($capped['ids'] === []) {
            return ['ids' => [], 'truncated' => $capped['truncated']];
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();
        $query = RediAdminGroup::query()->whereIn('id', $capped['ids']);
        if (! $isPlatformAdministrator) {
            $query->where('admin_id', $sessionAdminId);
        }

        return [
            'ids' => $query->orderBy('id')->pluck('id')->all(),
            'truncated' => $capped['truncated'],
        ];
    }
}
