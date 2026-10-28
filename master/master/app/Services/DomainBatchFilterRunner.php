<?php

namespace App\Services;

use App\Support\AdminTenantContext;
use App\Support\BatchSelectionLimiter;
use Illuminate\Http\Request;

class DomainBatchFilterRunner
{
    /** @var list<string> */
    public const ACTIONS = [
        'apply_cert',
        'redeploy',
        'enable',
        'disable',
        'delete',
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

        if (in_array($action, ['delete', 'disable'], true) && ! DomainListFilter::hasAnyFilter($filterRequest)) {
            return [
                'ok' => false,
                'message' => __('redi-admin-domain.batch_filter.errors.filter_required_destructive'),
                'count' => 0,
            ];
        }

        $selection = DomainListFilter::limitedSelection($filterRequest);
        $ids = $selection['ids'];
        $filterTruncated = $selection['truncated'];
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
                $msg = $this->finishSuccessMessage($msg, $filterTruncated + $dispatch['truncated'], $filterRequest);

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
                $msg = $this->finishSuccessMessage($msg, $filterTruncated + $redeploy['truncated'], $filterRequest);

                return ['ok' => true, 'message' => $msg, 'count' => $redeploy['queued']];

            case 'enable':
                $result = $mutator->setStatusOwned($ids, 1, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'enable', $filterTruncated, $filterRequest);

            case 'disable':
                $result = $mutator->setStatusOwned($ids, 2, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'disable', $filterTruncated, $filterRequest);

            case 'delete':
                $result = $mutator->deleteOwned($ids, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'delete', $filterTruncated, $filterRequest);
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

    protected function finishSuccessMessage(string $message, int $truncated, Request $filterRequest): string
    {
        $message = $this->withTruncation($message, $truncated);
        if (! DomainListFilter::hasAnyFilter($filterRequest)) {
            $message .= __('redi-admin-domain.batch_filter.warnings.unfiltered', [
                'max' => DomainBatchMutator::MAX_IDS_PER_BATCH,
            ]);
        }

        return $message;
    }

    /**
     * @param  array{updated: int, skipped: int, errors: string[], truncated?: int}  $result
     */
    protected function mutatorSummary(array $result, string $actionKey, int $filterTruncated = 0, ?Request $filterRequest = null): array
    {
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
            $filterTruncated + (int) ($result['truncated'] ?? 0),
            $filterRequest ?? request()
        );

        return ['ok' => true, 'message' => $msg, 'count' => $result['updated']];
    }
}
