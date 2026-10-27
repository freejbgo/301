<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediAdminGroup;
use App\Models\RediDomainCert;
use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;

class DomainBatchMutator
{
    public const MAX_IDS_PER_BATCH = 500;

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function setStatusOwned(
        array $domainIds,
        int $status,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        if (! in_array($status, [1, 2], true)) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.status_invalid');

            return $result;
        }

        $ids = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
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
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        $skipUrl = DomainRecordNormalizer::normalizeSkipUrl($rawSkipUrl);
        if ($skipUrl === '') {
            $result['errors'][] = __('redi-admin-domain.validation.skip_url_invalid');

            return $result;
        }

        $ids = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $domains = RediAdminDomain::query()->whereIn('id', $ids)->orderBy('id')->get();

        foreach ($domains as $domain) {
            if ((string) $domain->skip_url === $skipUrl) {
                $result['skipped']++;

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
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function moveGroupOwned(
        array $domainIds,
        int $groupId,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];

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

        $ids = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $domains = RediAdminDomain::query()->whereIn('id', $ids)->orderBy('id')->get();

        foreach ($domains as $domain) {
            if ((int) $domain->group_id === $groupId) {
                $result['skipped']++;

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
            } catch (\Illuminate\Validation\ValidationException $e) {
                $label = OpsDisplaySanitizer::field($domain->original_url, '—', 253);
                $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.row_save', [
                    'label' => $label,
                    'reason' => $e->validator->errors()->first() ?: __('redi-admin-domain.batch_mutator.errors.save_failed'),
                ]);
                continue;
            }
            $result['updated']++;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @param  ''|'0'|'1'  $forceHttps  '' = no change, '1' = on, '0' = off
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function setForceHttpsOwned(
        array $domainIds,
        string $forceHttps,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator = null
    ): array {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        if ($forceHttps === '') {
            return $result;
        }

        if (! in_array($forceHttps, ['0', '1'], true)) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.force_https_invalid');

            return $result;
        }

        $wantsOn = $forceHttps === '1';
        $ids = $this->normalizeOwnedIds($domainIds, $sessionAdminId, $isPlatformAdministrator);
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-domain.batch_mutator.errors.no_domains');

            return $result;
        }

        $domains = RediAdminDomain::query()->whereIn('id', $ids)->orderBy('id')->get();

        foreach ($domains as $domain) {
            $current = (bool) $domain->force_https;
            if ($current === $wantsOn) {
                $result['skipped']++;

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
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function applyEditsOwned(
        array $domainIds,
        int $sessionAdminId,
        ?string $skipUrl,
        ?int $groupId,
        string $forceHttps,
        ?bool $isPlatformAdministrator = null
    ): array {
        $merged = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        $hasSkip = $skipUrl !== null && trim($skipUrl) !== '';
        $hasGroup = $groupId !== null && $groupId > 0;
        $hasHttps = $forceHttps !== '';

        if (! $hasSkip && ! $hasGroup && ! $hasHttps) {
            $merged['errors'][] = __('redi-admin-domain.batch_mutator.errors.nothing_to_apply');

            return $merged;
        }

        if ($hasGroup) {
            $part = $this->moveGroupOwned($domainIds, (int) $groupId, $sessionAdminId, $isPlatformAdministrator);
            $merged = $this->mergeResults($merged, $part);
        }

        if ($hasSkip) {
            $part = $this->setSkipUrlOwned($domainIds, (string) $skipUrl, $sessionAdminId, $isPlatformAdministrator);
            $merged = $this->mergeResults($merged, $part);
        }

        if ($hasHttps) {
            $part = $this->setForceHttpsOwned($domainIds, $forceHttps, $sessionAdminId, $isPlatformAdministrator);
            $merged = $this->mergeResults($merged, $part);
        }

        return $merged;
    }

    /**
     * @param  array<int, int|string>  $domainIds
     * @return array<int, int>
     */
    protected function normalizeOwnedIds(
        array $domainIds,
        int $sessionAdminId,
        ?bool $isPlatformAdministrator
    ): array {
        $domainIds = array_values(array_unique(array_filter(array_map('intval', $domainIds), static fn (int $id): bool => $id > 0)));
        if ($domainIds === []) {
            return [];
        }
        if (count($domainIds) > self::MAX_IDS_PER_BATCH) {
            $domainIds = array_slice($domainIds, 0, self::MAX_IDS_PER_BATCH);
        }

        $isPlatformAdministrator = $isPlatformAdministrator ?? AdminTenantContext::isPlatformAdministrator();

        $query = RediAdminDomain::query()->whereIn('id', $domainIds);
        if (! $isPlatformAdministrator) {
            $query->where('admin_id', $sessionAdminId);
        }

        return $query->orderBy('id')->pluck('id')->all();
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[]}  $a
     * @param  array{updated: int, skipped: int, errors: string[]}  $b
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    protected function mergeResults(array $a, array $b): array
    {
        return [
            'updated' => $a['updated'] + $b['updated'],
            'skipped' => $a['skipped'] + $b['skipped'],
            'errors' => array_merge($a['errors'], $b['errors']),
        ];
    }
}
