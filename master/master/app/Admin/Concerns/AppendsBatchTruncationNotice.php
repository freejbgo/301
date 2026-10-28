<?php

namespace App\Admin\Concerns;

use App\Support\BatchSelectionLimiter;

trait AppendsBatchTruncationNotice
{
    protected function withTruncationNotice(string $summary, int $truncated, int $max = 500): string
    {
        return BatchSelectionLimiter::appendTruncationNotice($summary, $truncated, $max);
    }
}
