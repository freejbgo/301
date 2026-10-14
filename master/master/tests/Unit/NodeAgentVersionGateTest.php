<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\NodeAgentController;
use Tests\TestCase;

class NodeAgentVersionGateTest extends TestCase
{
    public function test_no_enforcement_when_min_version_unset(): void
    {
        config(['redi.min_agent_version' => '']);

        $gate = $this->gate();

        $this->assertNull($gate(null));
        $this->assertNull($gate('0.0.1'));
    }

    public function test_rejects_missing_version_when_min_set(): void
    {
        config(['redi.min_agent_version' => '0.1.2']);

        $gate = $this->gate();

        $this->assertNotNull($gate(null));
        $this->assertNotNull($gate(''));
        $this->assertSame(426, $gate(null)->getStatusCode());
    }

    public function test_rejects_version_below_min(): void
    {
        config(['redi.min_agent_version' => '0.1.2']);

        $gate = $this->gate();

        $response = $gate('0.1.0');
        $this->assertNotNull($response);
        $this->assertSame(426, $response->getStatusCode());
        $this->assertNull($gate('0.1.2'));
        $this->assertNull($gate('0.2.0'));
    }

    private function gate()
    {
        $controller = new class extends NodeAgentController {
            public function check(?string $version)
            {
                return $this->rejectIfAgentTooOld($version);
            }
        };

        return \Closure::fromCallable([$controller, 'check']);
    }
}
