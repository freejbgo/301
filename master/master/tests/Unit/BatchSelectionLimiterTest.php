<?php

namespace Tests\Unit;

use App\Support\BatchSelectionLimiter;
use Tests\TestCase;

class BatchSelectionLimiterTest extends TestCase
{
    public function test_caps_ids_and_reports_truncated(): void
    {
        $ids = range(1, 505);
        $out = BatchSelectionLimiter::intIdsCapped($ids, 500);

        $this->assertCount(500, $out['ids']);
        $this->assertSame(5, $out['truncated']);
    }
}
