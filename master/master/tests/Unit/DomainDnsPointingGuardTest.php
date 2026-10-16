<?php

namespace Tests\Unit;

use App\Services\DomainDnsPointingGuard;
use Tests\TestCase;

class DomainDnsPointingGuardTest extends TestCase
{
    public function test_enable_guard_disabled_by_config(): void
    {
        config(['redi.require_dns_pointing_to_enable' => false]);

        DomainDnsPointingGuard::assertCanEnable(1, 'any.example');

        $this->assertFalse(DomainDnsPointingGuard::mustPointToEdgeBeforeEnable());
    }

    public function test_must_point_defaults_true(): void
    {
        config(['redi.require_dns_pointing_to_enable' => true]);

        $this->assertTrue(DomainDnsPointingGuard::mustPointToEdgeBeforeEnable());
    }
}
