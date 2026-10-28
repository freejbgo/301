<?php

namespace App\Support;

use App\Services\DomainBatchMutator;

class BatchSelectionLimiter
{
    /**
     * @param  array<int, int|string>  $rawIds
     * @return array{ids: list<int>, truncated: int}
     */
    public static function intIdsCapped(array $rawIds, int $max): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn (int $id): bool => $id > 0
        )));

        $truncated = 0;
        if (count($ids) > $max) {
            $truncated = count($ids) - $max;
            $ids = array_slice($ids, 0, $max);
        }

        return ['ids' => $ids, 'truncated' => $truncated];
    }

    public static function appendTruncationNotice(string $message, int $truncated, int $max = DomainBatchMutator::MAX_IDS_PER_BATCH): string
    {
        if ($truncated <= 0) {
            return $message;
        }

        return $message.__('redi-admin-domain.actions.batch_mutator.truncated_suffix', [
            'count' => $truncated,
            'max' => $max,
        ]);
    }
}
