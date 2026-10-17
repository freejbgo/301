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

    public function test_sanitize_returns_null_for_control_chars(): void
    {
        $this->assertNull(RediAgentVersionGate::sanitize("0.1.3\n"));
    }

    public function test_resolve_from_input_prefers_body_over_stored(): void
    {
        $resolved = RediAgentVersionGate::resolveFromInput(['version' => '0.1.5'], '0.1.3');
        $this->assertSame('0.1.5', $resolved);
    }

    public function test_resolve_from_input_uses_stored_when_body_omits_version(): void
    {
        $resolved = RediAgentVersionGate::resolveFromInput([], '0.1.3');
        $this->assertSame('0.1.3', $resolved);
    }

    public function test_resolve_from_input_does_not_fallback_when_body_version_invalid(): void
    {
        $resolved = RediAgentVersionGate::resolveFromInput(['version' => "0.1.3\n"], '0.1.3');
        $this->assertNull($resolved);
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
