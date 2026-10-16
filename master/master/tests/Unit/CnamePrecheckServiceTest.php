<?php

namespace Tests\Unit;

use App\Services\CnamePrecheckService;
use Tests\TestCase;

class CnamePrecheckServiceTest extends TestCase
{
    public function test_points_to_edge_returns_false_for_empty_host(): void
    {
        $this->assertFalse(CnamePrecheckService::pointsToEdge('', 'edge.example.com'));
        $this->assertFalse(CnamePrecheckService::pointsToEdge('customer.example.com', ''));
    }

    public function test_points_to_edge_cached_returns_false_for_empty_host(): void
    {
        $this->assertFalse(CnamePrecheckService::pointsToEdgeCached('', 'edge.example.com'));
    }

    public function test_points_to_edge_returns_false_for_invalid_host_label(): void
    {
        $this->assertFalse(CnamePrecheckService::pointsToEdge('1.2.3.4', 'edge.example.com'));
        $this->assertFalse(CnamePrecheckService::pointsToEdge('customer.example.com', '1.2.3.4'));
    }
}
