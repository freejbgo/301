<?php

namespace Tests\Unit;

use App\Models\RediAcmeChallenge;
use InvalidArgumentException;
use Tests\TestCase;

class RediAcmeChallengeTest extends TestCase
{
    public function test_upsert_rejects_invalid_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ACME challenge token');

        RediAcmeChallenge::upsertForDomain('valid.example', '../bad', 'body', now()->addHour());
    }

    public function test_upsert_rejects_invalid_domain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP-01 hostname');

        RediAcmeChallenge::upsertForDomain('1.2.3.4', 'ok-token', 'body', now()->addHour());
    }

    public function test_upsert_rejects_control_chars_in_response_body(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ACME HTTP-01 response body');

        RediAcmeChallenge::upsertForDomain('valid.example', 'ok-token', "bo\r\ndy", now()->addHour());
    }
}
