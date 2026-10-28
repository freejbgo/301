<?php

namespace App\Services;

use App\Support\AdminTenantContext;
use Illuminate\Http\Request;

class DomainBatchFilterRunner
{
    /** @var list<string> */
    public const ACTIONS = [
        'apply_cert',
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

        $ids = DomainListFilter::limitedIds($filterRequest);
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
                $count = DomainCertificateQueue::dispatchOwned($ids, $sessionAdminId, $isPlatform);
                if ($count === 0) {
                    return [
                        'ok' => false,
                        'message' => __('redi-admin-domain.actions.batch_apply.error'),
                        'count' => 0,
                    ];
                }

                return [
                    'ok' => true,
                    'message' => __('redi-admin-domain.batch_filter.success.apply_cert', [
                        'count' => $count,
                        'cap' => DomainBatchMutator::MAX_IDS_PER_BATCH,
                    ]),
                    'count' => $count,
                ];

            case 'enable':
                $result = $mutator->setStatusOwned($ids, 1, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'enable');

            case 'disable':
                $result = $mutator->setStatusOwned($ids, 2, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'disable');

            case 'delete':
                $result = $mutator->deleteOwned($ids, $sessionAdminId, $isPlatform);

                return $this->mutatorSummary($result, 'delete');
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
    protected function mutatorSummary(array $result, string $actionKey): array
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

        return ['ok' => true, 'message' => $msg, 'count' => $result['updated']];
    }
}
