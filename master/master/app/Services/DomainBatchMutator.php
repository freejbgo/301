<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediAdminGroup;
use App\Models\RediDomainCert;
use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;
use App\Support\OpsDisplaySanitizer;

class DomainBatchMutator
{
    public const MAX_IDS_PER_BATCH = 500;

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function setStatusOwned(
        array $domainIds,
        int $status,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        if (! in_array($status, [1, 2], true)) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.status_invalid');

            return $result;
        }

        $normalized = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $domains = RediAdminDomain::query()->whereIn('id', $ids)->orderBy('id')->get();

        foreach ($domains as $domain) {
            if ((int) $domain->status === $status) {
                $result['skipped']++;

                continue;
            }

            if ($status === 1) {
                try {
                    DomainDnsPointingGuard::assertCanEnable((int) $domain->admin_id, (string) $domain->original_url);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
                    $msg = $e->validator->errors()->first('status')
                        ?: $e->validator->errors()->first('original_url')
                        ?: __('redi-admin-domain.validation.dns_enable_url');
                    $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_enable', [
                        'label' => $label,
                        'reason' => $msg,
                    ]);
                    continue;
                }
            }

            $domain->status = $status;
            $domain->save();
            $result['updated']++;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function setSkipUrlOwned(
        array $domainIds,
        string $rawSkipUrl,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($rawSkipUrl);
        if ($skipUrl === '') {
            $result['errors'][] = __('redi-admin-domain.validation.skip_url_invalid');

            return $result;
        }

        $normalized = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        if ($normalized['ids'] === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }
        $result = $this->applySkipUrlOnOwnedIds($normalized['ids'], $skipUrl);
        $result['truncated'] = $normalized['truncated'];

        return $result;
    }

    /**
     * 分组行「按组改跳转」：组内全部域名，不受勾选 500 条上限。
     *
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function setSkipUrlForGroupOwned(
        int $groupId,
        string $rawSkipUrl,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($rawSkipUrl);
        if ($skipUrl === '') {
            return [
                'updated' => 0,
                'skipped' => 0,
                'errors' => [__('redi-admin-domain.validation.skip_url_invalid')],
                'truncated' => 0,
            ];
        }

        if ($groupId <= 0) {
            return [
                'updated' => 0,
                'skipped' => 0,
                'errors' => [__('redi-admin-domain.validation.group_invalid')],
                'truncated' => 0,
            ];
        }

        $group = RediAdminGroup::query()->find($groupId);
        if (! $group) {
            return [
                'updated' => 0,
                'skipped' => 0,
                'errors' => [__('redi-admin-domain.validation.group_invalid')],
                'truncated' => 0,
            ];
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();
        if (! $isPlatformAdministrator && (int) $group->admin_id !== $sessionAdminId) {
            return [
                'updated' => 0,
                'skipped' => 0,
                'errors' => [__('redi-admin-domain.validation.group_wrong_owner')],
                'truncated' => 0,
            ];
        }

        $ids = RediAdminDomain::query()
            ->where('group_id', $groupId)
            ->where('admin_id', (int) $group->admin_id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $part = $this->applySkipUrlOnOwnedIds($ids, $skipUrl);

        return array_merge(['truncated' => 0], $part);
    }

    /**
     * @param  list<int>  $ownedIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    protected function applySkipUrlOnOwnedIds(array $ownedIds, string $skipUrl): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'changed_ids' => [], 'skipped_ids' => []];
        if ($ownedIds === []) {
            return $result;
        }

        $domains = RediAdminDomain::query()->whereIn('id', $ownedIds)->orderBy('id')->get();

        foreach ($domains as $domain) {
            if ((string) $domain->skip_url === $skipUrl) {
                $result['skipped']++;
                $result['skipped_ids'][] = (int) $domain->id;

                continue;
            }

            $domain->skip_url = $skipUrl;
            try {
                $domain->save();
            } catch (\Illuminate\Validation\ValidationException $e) {
                $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
                $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_save', [
                    'label' => $label,
                    'reason' => $e->validator->errors()->first() ?: __('redi-admin-domain.batch_mutator.errors.save_failed'),
                ]);
                continue;
            }
            $result['updated']++;
            $result['changed_ids'][] = (int) $domain->id;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function moveGroupOwned(
        array $domainIds,
        int $groupId,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        if ($groupId <= 0) {
            $result['errors'][] = __('redi-admin-domain.validation.group_invalid');

            return $result;
        }

        $group = RediAdminGroup::query()->find($groupId);
        if (! $group) {
            $result['errors'][] = __('redi-admin-domain.validation.group_invalid');

            return $result;
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();
        if (! $isPlatformAdministrator && (int) $group->admin_id !== $sessionAdminId) {
            $result['errors'][] = __('redi-admin-domain.validation.group_wrong_owner');

            return $result;
        }

        $normalized = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        $result['truncated'] = $normalized['truncated'];
        if ($normalized['ids'] === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $part = $this->applyMoveGroupOnOwnedIds(
            $normalized['ids'],
            $group,
            $sessionAdminId,
            $isPlatformAdministrator
        );

        return array_merge($result, $part);
    }

    /**
     * @param  list<int>  $ownedIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    protected function applyMoveGroupOnOwnedIds(
        array $ownedIds,
        RediAdminGroup $group,
        int $sessionAdminId,
        bool $isPlatformAdministrator
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'changed_ids' => [], 'skipped_ids' => []];
        $groupId = (int) $group->id;

        $domains = RediAdminDomain::query()->whereIn('id', $ownedIds)->orderBy('id')->get();

        foreach ($domains as $domain) {
            if ((int) $domain->group_id === $groupId) {
                $result['skipped']++;
                $result['skipped_ids'][] = (int) $domain->id;

                continue;
            }

            if (! $isPlatformAdministrator && (int) $domain->admin_id !== (int) $group->admin_id) {
                $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
                $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_group_owner', ['label' => $label]);
                continue;
            }

            $domain->group_id = $groupId;
            if ($isPlatformAdministrator) {
                $domain->admin_id = (int) $group->admin_id;
                $domain->admin_name = (string) $group->admin_name;
            }

            try {
                $domain->save();
                DomainCertificateAdminSync::realignCertsForDomain($domain);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
                $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_save', [
                    'label' => $label,
                    'reason' => $e->validator->errors()->first() ?: __('redi-admin-domain.batch_mutator.errors.save_failed'),
                ]);
                continue;
            }
            $result['updated']++;
            $result['changed_ids'][] = (int) $domain->id;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @param  ''|'0'|'1'  $forceHttps  '' = no change, '1' = on, '0' = off
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function setForceHttpsOwned(
        array $domainIds,
        string $forceHttps,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        if ($forceHttps === '') {
            return $result;
        }

        if (! in_array($forceHttps, ['0', '1'], true)) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.force_https_invalid');

            return $result;
        }

        $wantsOn = $forceHttps === '1';
        $normalized = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        $result['truncated'] = $normalized['truncated'];
        if ($normalized['ids'] === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $part = $this->applyForceHttpsOnOwnedIds($normalized['ids'], $wantsOn);

        return array_merge($result, $part);
    }

    /**
     * @param  list<int>  $ownedIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    protected function applyForceHttpsOnOwnedIds(array $ownedIds, bool $wantsOn): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'changed_ids' => [], 'skipped_ids' => []];
        $domains = RediAdminDomain::query()->whereIn('id', $ownedIds)->orderBy('id')->get();

        foreach ($domains as $domain) {
            $current = (bool) $domain->force_https;
            if ($current === $wantsOn) {
                $result['skipped']++;
                $result['skipped_ids'][] = (int) $domain->id;

                continue;
            }

            if ($wantsOn) {
                $certActive = RediDomainCert::query()
                    ->where('domain_id', $domain->id)
                    ->where('status', RediDomainCert::STATUS_ACTIVE)
                    ->exists();
                if (! $certActive) {
                    $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
                    $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_force_https', ['label' => $label]);
                    continue;
                }
            }

            $domain->force_https = $wantsOn;
            $domain->save();
            $result['updated']++;
            $result['changed_ids'][] = (int) $domain->id;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function deleteOwned(
        array $domainIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];

        $normalized = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $domains = RediAdminDomain::query()->whereIn('id', $ids)->orderBy('id')->get();

        foreach ($domains as $domain) {
            $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
            try {
                $domain->delete();
                $result['updated']++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_delete', [
                    'label' => $label,
                    'reason' => $e->validator->errors()->first() ?: __('redi-admin-domain.batch_mutator.errors.save_failed'),
                ]);
            } catch (\Throwable $e) {
                $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_delete', [
                    'label' => $label,
                    'reason' => __('redi-admin-domain.batch_mutator.errors.save_failed'),
                ]);
            }
        }

        return $result;
    }

    public function applyEditsOwned(
        array $domainIds,
        int $sessionAdminId,
        ?string $skipUrl,
        ?int $groupId,
        string $forceHttps,
        ?bool $isPlatformAdministrator = null
    ): array {
        $merged = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0, 'changed_ids' => [], 'skipped_ids' => []];

        $hasSkip = $skipUrl !== null && trim($skipUrl) !== '';
        $hasGroup = $groupId !== null && $groupId > 0;
        $hasHttps = $forceHttps !== '';

        if (! $hasSkip && ! $hasGroup && ! $hasHttps) {
            $merged['errors'][] = __('redi-admin-domain.batch_mutator.errors.nothing_to_apply');

            return $merged;
        }

        $normalized = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        $merged['truncated'] = $normalized['truncated'];
        $ownedIds = $normalized['ids'];
        if ($ownedIds === []) {
            $merged['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $merged;
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();

        if ($hasGroup) {
            if ($groupId === null || $groupId <= 0) {
                $merged['errors'][] = __('redi-admin-domain.validation.group_invalid');
            } else {
                $group = RediAdminGroup::query()->find($groupId);
                if (! $group) {
                    $merged['errors'][] = __('redi-admin-domain.validation.group_invalid');
                } elseif (! $isPlatformAdministrator && (int) $group->admin_id !== $sessionAdminId) {
                    $merged['errors'][] = __('redi-admin-domain.validation.group_wrong_owner');
                } else {
                    $part = $this->applyMoveGroupOnOwnedIds(
                        $ownedIds,
                        $group,
                        $sessionAdminId,
                        (bool) $isPlatformAdministrator
                    );
                    $merged = $this->mergeEditResults($merged, $part);
                }
            }
        }

        if ($hasSkip) {
            $skipUrl = DomainRecordNormalizer::normalizeSkipUrl((string) $skipUrl);
            if ($skipUrl === '') {
                $merged['errors'][] = __('redi-admin-domain.validation.skip_url_invalid');
            } else {
                $part = $this->applySkipUrlOnOwnedIds($ownedIds, $skipUrl);
                $merged = $this->mergeEditResults($merged, $part);
            }
        }

        if ($hasHttps) {
            if (! in_array($forceHttps, ['0', '1'], true)) {
                $merged['errors'][] = __('redi-admin-domain.batch_mutator.errors.force_https_invalid');
            } else {
                $part = $this->applyForceHttpsOnOwnedIds($ownedIds, $forceHttps === '1');
                $merged = $this->mergeEditResults($merged, $part);
            }
        }

        unset($merged['changed_ids'], $merged['skipped_ids']);

        return $merged;
    }

    /**
     * @param  array<int, int|string>|list<int>  $domainIds
     * @return array{ids: list<int>, truncated: int}
     */
    protected function normalizeOwnedIds(
        array $domainIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator
    ): array {
        $capped = BatchSelectionLimiter::intIdsCapped($domainIds, self::MAX_IDS_PER_BATCH);
        if ($capped['ids'] === []) {
            return ['ids' => [], 'truncated' => $capped['truncated']];
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();

        $query = RediAdminDomain::query()->whereIn('id', $capped['ids']);
        if (! $isPlatformAdministrator) {
            $query->where('admin_id', $sessionAdminId);
        }

        return [
            'ids' => $query->orderBy('id')->pluck('id')->all(),
            'truncated' => $capped['truncated'],
        ];
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[], truncated?: int}  $a
     * @param  array{updated: int, skipped: int, errors: string[], truncated?: int}  $b
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    protected function mergeResults(array $a, array $b): array
    {
        return [
            'updated' => $a['updated'] + $b['updated'],
            'skipped' => $a['skipped'] + $b['skipped'],
            'errors' => array_merge($a['errors'], $b['errors']),
            'truncated' => (int) ($a['truncated'] ?? 0),
        ];
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[], truncated?: int, changed_ids?: list<int>}  $a
     * @param  array{updated: int, skipped: int, errors: string[], changed_ids?: list<int>}  $b
     * @return array{updated: int, skipped: int, errors: string[], truncated: int, changed_ids: list<int>}
     */
    protected function mergeEditResults(array $a, array $b): array
    {
        $changed = array_values(array_unique(array_merge($a['changed_ids'] ?? [], $b['changed_ids'] ?? [])));
        $skippedIds = array_values(array_unique(array_merge($a['skipped_ids'] ?? [], $b['skipped_ids'] ?? [])));
        $skippedIds = array_values(array_diff($skippedIds, $changed));

        return [
            'updated' => count($changed),
            'changed_ids' => $changed,
            'skipped' => count($skippedIds),
            'skipped_ids' => $skippedIds,
            'errors' => array_merge($a['errors'], $b['errors']),
            'truncated' => (int) ($a['truncated'] ?? 0),
        ];
    }
}
