<?php

namespace Tests\Unit;

use Tests\TestCase;

class RediPublishPoolAaaaConfigTest extends TestCase
{
    public function test_publish_pool_aaaa_defaults_to_false(): void
    {
        $saved = getenv('REDI_PUBLISH_POOL_AAAA');
        putenv('REDI_PUBLISH_POOL_AAAA');

        $redi = require config_path('redi.php');

        if ($saved === false) {
            putenv('REDI_PUBLISH_POOL_AAAA');
        } else {
            putenv('REDI_PUBLISH_POOL_AAAA='.$saved);
        }

        $this->assertFalse($redi['publish_pool_aaaa']);
        $this->assertFalse(config('redi.publish_pool_aaaa', false));
    }
}
