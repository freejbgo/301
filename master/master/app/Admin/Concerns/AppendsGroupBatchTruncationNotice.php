<?php

namespace App\Admin\Concerns;

use App\Services\GroupBatchMutator;
use App\Support\BatchSelectionLimiter;

trait AppendsGroupBatchTruncationNotice
{
    protected function withGroupTruncationNotice(string $summary, int $truncated): string
    {
        return BatchSelectionLimiter::appendTruncationNotice(
            $summary,
            $truncated,
            GroupBatchMutator::MAX_IDS_PER_BATCH
        );
    }
}
