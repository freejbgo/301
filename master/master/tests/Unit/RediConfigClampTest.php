<?php

namespace Tests\Unit;

use Tests\TestCase;

class RediConfigClampTest extends TestCase
{
    public function test_clamps_extreme_env_values(): void
    {
        $keys = [
            'REDI_HEALTH_QUEUE_STALE_HOURS' => '9999',
            'REDI_HEALTH_VALIDATING_STUCK_HOURS' => '0',
            'REDI_RENEW_VALIDATING_STUCK_HOURS' => '-5',
            'REDI_ACME_HTTP01_POLL_SECONDS' => '120',
            'REDI_HEALTH_QUEUE_PENDING_THRESHOLD' => '0',
        ];
        $saved = [];
        foreach ($keys as $key => $value) {
            $saved[$key] = getenv($key);
            putenv($key.'='.$value);
        }

        $redi = require config_path('redi.php');

        foreach ($saved as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key.'='.$value);
            }
        }

        $this->assertSame(720, $redi['health_queue_stale_hours']);
        $this->assertSame(1, $redi['health_validating_stuck_hours']);
        $this->assertSame(1, $redi['renew_validating_stuck_hours']);
        $this->assertSame(60, $redi['acme_http01_poll_seconds']);
        $this->assertSame(1, $redi['health_queue_pending_threshold']);
    }
}
