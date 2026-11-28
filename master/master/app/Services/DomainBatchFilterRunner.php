<?php

namespace App\Services;

use App\Support\AdminBatchOperationAudit;
use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;
use Illuminate\Http\Request;

class DomainBatchFilterRunner
{
    /** @var string */
    protected $selectionMode = 'list_filter';

    /** @var list<string> */
    public const ACTIONS = [
        'apply_cert',
        'redeploy',
        'enable',
        'disable',
        'delete',
        'set_skip_url',
        'force_https_on',
    ];

    /**
     * @return array{ok: bool, message: string, count: int}
     */
    public function run(string $action, Request $filterRequest, int $sessionAdminId): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return [
                'ok' => false,
                'message' => __('redi-admin-domain.batch_filter.errors.action_invalid'),
                'count' => 0,
            ];
        }

        if ($action === 'redeploy' && ! AdminTenantContext::isPlatformAdministrator()) {
            return [
                'ok' => false,
                'message' => __('redi-auth.admin_only'),
                'count' => 0,
            ];
        }

        $explicitIds = $this->explicitSelectedIds($filterRequest);
        if ($explicitIds !== []) {
            $capped = BatchSelectionLimiter::intIdsCapped($explicitIds, DomainBatchMutator::MAX_IDS_PER_BATCH);
            $ids = $capped['ids'];
            $filterTruncated = $capped['truncated'];
            $this->selectionMode = 'selected_rows';
        } elseif (in_array($action, ['delete', 'disable', 'apply_cert', 'redeploy', 'enable', 'set_skip_url', 'force_https_on'], true)
            && ! DomainListFilter::hasAnyFilter($filterRequest)) {
            return [
                'ok' => false,
                'message' => __('redi-admin-domain.batch_filter.errors.filter_required_sensitive'),
                'count' => 0,
            ];
        } else {
            $selection = DomainListFilter::limitedSelection($filterRequest);
            $ids = $selection['ids'];
            $filterTruncated = $selection['truncated'];
            $this->selectionMode = 'list_filter';
        }
        if ($ids === []) {
            return [
                'ok' => false,
                'message' => __('redi-admin-domain.batch_filter.errors.no_match'),
                'count' => 0,
            ];
        }

        $isPlatform = AdminTenantContext::isPlatformAdministrator();
        $mutator = new DomainBatchMutator();

        switch ($action) {
            case 'apply_cert':
                $dispatch = DomainCertificateQueue::dispatchOwned($ids, $sessionAdminId, $isPlatform);
                if ($dispatch['dispatched'] === 0) {
                    return [
                        'ok' => false,
                        'message' => __('redi-admin-domain.actions.batch_apply.error'),
                        'count' => 0,
                    ];
                }

                $msg = __('redi-admin-domain.batch_filter.success.apply_cert', [
                    'count' => $dispatch['dispatched'],
                    'cap' => DomainBatchMutator::MAX_IDS_PER_BATCH,
                ]);
                $msg = $this->finishSuccessMessage($msg, $filterTruncated + $dispatch['truncated']);
                $this->auditFilterRun('apply_cert', $sessionAdminId, $dispatch['dispatched'], [
                    'skipped' => 0,
                    'truncated' => $filterTruncated + $dispatch['truncated'],
                    'error_count' => 0,
                ], $filterRequest);

                return [
                    'ok' => true,
                    'message' => $msg,
                    'count' => $dispatch['dispatched'],
                ];

            case 'redeploy':
                $redeploy = DomainCertificateRedeployQueue::redeployOwned($ids, $sessionAdminId, $isPlatform);
                if ($redeploy['queued'] === 0) {
                    $msg = $redeploy['skipped'] > 0
                        ? __('redi-admin-domain.batch_filter.error_all.redeploy')
                        : __('redi-admin-domain.batch_filter.error_none.redeploy');

                    return ['ok' => false, 'message' => $msg, 'count' => 0];
                }

                $msg = __('redi-admin-domain.batch_filter.success.redeploy', [
                    'queued' => $redeploy['queued'],
                    'cap' => DomainBatchMutator::MAX_IDS_PER_BATCH,
                ]);
                if ($redeploy['skipped'] > 0) {
                    $msg .= __('redi-admin-domain.actions.batch_redeploy.success_skipped', [
                        'skipped' => $redeploy['skipped'],
                    ]);
                }
                $msg = $this->finishSuccessMessage($msg, $filterTruncated + $redeploy['truncated']);
                $this->auditFilterRun('redeploy', $sessionAdminId, $redeploy['queued'], [
                    'skipped' => (int) $redeploy['skipped'],
                    'truncated' => $filterTruncated + $redeploy['truncated'],
                    'error_count' => 0,
                ], $filterRequest);

                return ['ok' => true, 'message' => $msg, 'count' => $redeploy['queued']];

            case 'enable':
                $result = $mutator->setStatusOwned($ids, 1, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'enable', $sessionAdminId, $filterTruncated, $filterRequest);

            case 'disable':
                $result = $mutator->setStatusOwned($ids, 2, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'disable', $sessionAdminId, $filterTruncated, $filterRequest);

            case 'delete':
                $result = $mutator->deleteOwned($ids, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'delete', $sessionAdminId, $filterTruncated, $filterRequest);

            case 'set_skip_url':
                $rawSkipUrl = trim((string) $filterRequest->input('new_skip_url', ''));
                if (DomainRecordNormalizer::normalizeSkipUrl($rawSkipUrl) === '') {
                    return [
                        'ok' => false,
                        'message' => __('redi-admin-domain.validation.skip_url_invalid'),
                        'count' => 0,
                    ];
                }
                $result = $mutator->setSkipUrlOwned($ids, $rawSkipUrl, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'set_skip_url', $sessionAdminId, $filterTruncated, $filterRequest);

            case 'force_https_on':
                $result = $mutator->setForceHttpsOwned($ids, '1', $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'force_https_on', $sessionAdminId, $filterTruncated, $filterRequest);
        }

        return [
            'ok' => false,
            'message' => __('redi-admin-domain.batch_filter.errors.action_invalid'),
            'count' => 0,
        ];
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[]}  $result
     * @return array{ok: bool, message: string, count: int}
     */
    protected function withTruncation(string $message, int $truncated): string
    {
        return BatchSelectionLimiter::appendTruncationNotice($message, $truncated);
    }

    protected function finishSuccessMessage(string $message, int $truncated): string
    {
        return $this->withTruncation($message, $truncated);
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[], truncated?: int}  $result
     */
    protected function mutatorSummary(
        array $result,
        string $actionKey,
        int $sessionAdminId,
        int $filterTruncated = 0,
        ?Request $filterRequest = null
    ): array {
        if ($result['updated'] === 0) {
            $msg = $result['errors'] !== []
                ? __('redi-admin-domain.batch_filter.error_all.'.$actionKey)
                : __('redi-admin-domain.batch_filter.error_none.'.$actionKey);
            if ($result['errors'] !== []) {
                $msg .= "\n".implode("\n", array_slice($result['errors'], 0, 6));
            }

            return ['ok' => false, 'message' => $msg, 'count' => 0];
        }

        $msg = __('redi-admin-domain.batch_filter.success.'.$actionKey, [
            'count' => $result['updated'],
            'cap' => DomainBatchMutator::MAX_IDS_PER_BATCH,
        ]);
        if ($result['skipped'] > 0) {
            $msg .= __('redi-admin-domain.actions.batch_mutator.skipped_suffix', ['count' => $result['skipped']]);
        }
        if ($result['errors'] !== []) {
            $msg .= "\n".__('redi-admin-domain.actions.batch_mutator.partial_errors', [
                'count' => count($result['errors']),
            ]);
        }

        $msg = $this->finishSuccessMessage(
            $msg,
            $filterTruncated + (int) ($result['truncated'] ?? 0)
        );

        $request = $filterRequest ?? request();
        $this->auditFilterRun($actionKey, $sessionAdminId, (int) $result['updated'], [
            'skipped' => (int) $result['skipped'],
            'truncated' => $filterTruncated + (int) ($result['truncated'] ?? 0),
            'error_count' => count($result['errors']),
        ], $request);

        return ['ok' => true, 'message' => $msg, 'count' => $result['updated']];
    }

    /**
     * @param  array<string, int>  $metrics
     */
    protected function auditFilterRun(string $action, int $adminId, int $primaryCount, array $metrics, Request $filterRequest): void
    {
        AdminBatchOperationAudit::record(
            'domain',
            'filter.'.$action,
            $adminId,
            array_merge(['updated' => $primaryCount], $metrics),
            [
                'mode' => $this->selectionMode,
                'filtered' => DomainListFilter::hasAnyFilter($filterRequest),
                'filter_keys' => $this->activeFilterKeys($filterRequest),
            ]
        );
    }

    /**
     * @return list<int>
     */
    protected function explicitSelectedIds(Request $request): array
    {
        $raw = $request->input('selected_ids');
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $parts = is_array($raw)
            ? $raw
            : preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    protected function activeFilterKeys(Request $filterRequest): array
    {
        $keys = [];
        foreach (DomainListFilter::FILTER_KEYS as $key) {
            if ($filterRequest->filled($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
