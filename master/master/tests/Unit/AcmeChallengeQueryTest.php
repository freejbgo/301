<?php

namespace Tests\Unit;

use App\Services\AcmeChallengeQuery;
use Tests\TestCase;

class AcmeChallengeQueryTest extends TestCase
{
    public function test_http01_content_valid_rejects_control_chars(): void
    {
        $this->assertFalse(AcmeChallengeQuery::http01ContentValid('ok-token', "bo\r\ndy"));
        $this->assertTrue(AcmeChallengeQuery::http01ContentValid('ok-token', 'body'));
    }

    public function test_http01_content_valid_rejects_empty_token_or_body(): void
    {
        $this->assertFalse(AcmeChallengeQuery::http01ContentValid('', 'body'));
        $this->assertFalse(AcmeChallengeQuery::http01ContentValid('ok-token', '   '));
    }

    public function test_node_ready_row_requires_valid_host_and_content(): void
    {
        $this->assertTrue(AcmeChallengeQuery::nodeReadyRow('good.example', 'ok-token', 'body'));
        $this->assertFalse(AcmeChallengeQuery::nodeReadyRow('1.2.3.4', 'ok-token', 'body'));
        $this->assertFalse(AcmeChallengeQuery::nodeReadyRow('good.example', 'ok-token', "b\nody"));
        $this->assertFalse(AcmeChallengeQuery::nodeReadyRow("bad\x00.example", 'ok-token', 'body'));
        $this->assertFalse(AcmeChallengeQuery::http01ContentValid('ok-token', "body\n"));
    }
}
