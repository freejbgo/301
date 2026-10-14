<?php

namespace Tests\Unit;

use App\Support\RediAgentVersionGate;
use Tests\TestCase;

class NodeAgentVersionGateTest extends TestCase
{
    public function test_no_enforcement_when_min_version_unset(): void
    {
        config(['redi.min_agent_version' => '']);

        $this->assertNull(RediAgentVersionGate::rejectResponse(null));
        $this->assertNull(RediAgentVersionGate::rejectResponse('0.0.1'));
    }

    public function test_rejects_missing_version_when_min_set(): void
    {
        config(['redi.min_agent_version' => '0.1.2']);

        $this->assertNotNull(RediAgentVersionGate::rejectResponse(null));
        $this->assertNotNull(RediAgentVersionGate::rejectResponse(''));
        $this->assertSame(426, RediAgentVersionGate::rejectResponse(null)->getStatusCode());
    }

    public function test_rejects_version_below_min(): void
    {
        config(['redi.min_agent_version' => '0.1.2']);

        $response = RediAgentVersionGate::rejectResponse('0.1.0');
        $this->assertNotNull($response);
        $this->assertSame(426, $response->getStatusCode());
        $this->assertNull(RediAgentVersionGate::rejectResponse('0.1.2'));
        $this->assertNull(RediAgentVersionGate::rejectResponse('0.2.0'));
    }
}
