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
        $this->expectExceptionMessage('token 无效');

        RediAcmeChallenge::upsertForDomain('valid.example', '../bad', 'body', now()->addHour());
    }
}
