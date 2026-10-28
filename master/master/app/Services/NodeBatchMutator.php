<?php

namespace App\Services;

use App\Models\RediNode;
use App\Support\BatchSelectionLimiter;
use App\Support\OpsDisplaySanitizer;

class NodeBatchMutator
{
    public const MAX_IDS_PER_BATCH = 100;

    /** 批量重置 Token 上限（响应中会带回明文，限制条数）。 */
    public const MAX_TOKEN_ROTATE_BATCH = 20;

    /**
     * @param  array<int, int|string>  $nodeIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function setEnabled(array $nodeIds, bool $enabled): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];
        $normalized = $this->normalizeIds($nodeIds);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-node.batch_mutator.errors.no_nodes');

            return $result;
        }

        $nodes = RediNode::query()->whereIn('id', $ids)->orderBy('id')->get();
        foreach ($nodes as $node) {
            if ((bool) $node->enabled === $enabled) {
                $result['skipped']++;

                continue;
            }
            $node->enabled = $enabled;
            $node->save();
            $result['updated']++;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $nodeIds
     * @return array{updated: int, skipped: int, errors: string[], tokens: array<int, string>, truncated: int}
     */
    public function rotateTokens(array $nodeIds): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'tokens' => [], 'truncated' => 0];

        $normalized = $this->normalizeIds($nodeIds, self::MAX_TOKEN_ROTATE_BATCH);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-node.batch_mutator.errors.no_nodes');

            return $result;
        }

        if ($result['truncated'] > 0) {
            $result['errors'][] = __('redi-admin-node.batch_mutator.errors.token_cap', [
                'max' => self::MAX_TOKEN_ROTATE_BATCH,
            ]);
        }

        $nodes = RediNode::query()->whereIn('id', $ids)->orderBy('id')->get();
        foreach ($nodes as $node) {
            $plain = $node->issueApiTokenPlain();
            $node->save();
            $result['updated']++;
            $result['tokens'][(int) $node->id] = $plain;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $nodeIds
     * @return array{updated: int, skipped: int, errors: string[], truncated: int}
     */
    public function requeueFailed(array $nodeIds, CertificateDeployService $deployService): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'truncated' => 0];
        $normalized = $this->normalizeIds($nodeIds);
        $ids = $normalized['ids'];
        $result['truncated'] = $normalized['truncated'];
        if ($ids === []) {
            $result['errors'][] = __('redi-admin-node.batch_mutator.errors.no_nodes');

            return $result;
        }

        $nodes = RediNode::query()->whereIn('id', $ids)->orderBy('id')->get();
        foreach ($nodes as $node) {
            $count = $deployService->requeueFailedDeployments((int) $node->id, null);
            if ($count === 0) {
                $label = OpsDisplaySanitizer::field($node->name, '#'.$node->id, 120);
                $result['skipped']++;
                $result['errors'][] = __('redi-admin-node.batch_mutator.errors.requeue_empty', ['label' => $label]);
                continue;
            }
            $result['updated'] += $count;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string>  $nodeIds
     * @return array{ids: list<int>, truncated: int}
     */
    protected function normalizeIds(array $nodeIds, int $max = self::MAX_IDS_PER_BATCH): array
    {
        $capped = BatchSelectionLimiter::intIdsCapped($nodeIds, $max);
        if ($capped['ids'] === []) {
            return ['ids' => [], 'truncated' => $capped['truncated']];
        }

        return [
            'ids' => RediNode::query()->whereIn('id', $capped['ids'])->orderBy('id')->pluck('id')->all(),
            'truncated' => $capped['truncated'],
        ];
    }
}
