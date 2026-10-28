<?php

namespace App\Services;

use App\Support\AdminTenantContext;
use App\Models\RediAdminDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DomainListFilter
{
    public const FILTER_KEYS = ['group_id', 'original_url', 'skip_url', 'status', 'cert_filter'];

    /**
     * @return Builder<RediAdminDomain>
     */
    public static function filteredQuery(Request $request): Builder
    {
        $query = RediAdminDomain::query()
            ->with('certificate')
            ->orderBy('id');

        if (! AdminTenantContext::isPlatformAdministrator()) {
            $adminId = AdminTenantContext::sessionAdminId();
            if ($adminId > 0) {
                $query->where('admin_id', $adminId);
            }
        }

        if ($request->filled('group_id')) {
            $query->where('group_id', (int) $request->input('group_id'));
        }
        if ($request->filled('original_url')) {
            $query->where('original_url', 'like', '%'.addcslashes((string) $request->input('original_url'), '%_\\').'%');
        }
        if ($request->filled('skip_url')) {
            $query->where('skip_url', 'like', '%'.addcslashes((string) $request->input('skip_url'), '%_\\').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }
        if ($request->filled('cert_filter')) {
            RediAdminDomain::applyCertificateListFilter($query, (string) $request->input('cert_filter'));
        }

        return $query;
    }

    /**
     * @return array<int, int>
     */
    public static function limitedIds(Request $request, int $max = DomainBatchMutator::MAX_IDS_PER_BATCH): array
    {
        return self::limitedSelection($request, $max)['ids'];
    }

    /**
     * @return array{ids: list<int>, truncated: int, matched: int}
     */
    public static function limitedSelection(Request $request, int $max = DomainBatchMutator::MAX_IDS_PER_BATCH): array
    {
        $max = max(1, min($max, DomainBatchMutator::MAX_IDS_PER_BATCH));
        $query = self::filteredQuery($request);
        $matched = (int) $query->count();
        $ids = $query->limit($max)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $truncated = max(0, $matched - count($ids));

        return [
            'ids' => $ids,
            'truncated' => $truncated,
            'matched' => $matched,
        ];
    }

    public static function hasAnyFilter(Request $request): bool
    {
        foreach (self::FILTER_KEYS as $key) {
            if ($request->filled($key)) {
                return true;
            }
        }

        return false;
    }

    public static function mergeFilterIntoRequest(Request $request, array $input): Request
    {
        $bag = [];
        foreach (self::FILTER_KEYS as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $bag[$key] = $input[$key];
            }
        }

        return $request->duplicate($bag + $request->query->all());
    }
}
