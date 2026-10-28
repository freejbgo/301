<?php

namespace App\Admin\Concerns;

use App\Services\NodeBatchMutator;
use App\Support\BatchSelectionLimiter;

trait AppendsNodeBatchTruncationNotice
{
    protected function withNodeTruncationNotice(string $summary, int $truncated, int $max = NodeBatchMutator::MAX_IDS_PER_BATCH): string
    {
        return BatchSelectionLimiter::appendTruncationNotice($summary, $truncated, $max);
    }
}
