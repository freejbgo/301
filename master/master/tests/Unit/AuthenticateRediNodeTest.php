<?php

namespace Tests\Unit;

use Tests\TestCase;

class AuthenticateRediNodeTest extends TestCase
{
    public function test_node_api_returns_401_without_bearer_token(): void
    {
        $this->getJson('/api/nodes/revision')->assertStatus(401);
    }

    public function test_node_api_returns_401_with_malformed_bearer_token(): void
    {
        $this->withToken('not-a-node-token')
            ->getJson('/api/nodes/revision')
            ->assertStatus(401);
    }
}
