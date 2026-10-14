<?php

namespace App\Services;

use App\Models\RediAcmeChallenge;
use Illuminate\Database\Eloquent\Builder;

class AcmeChallengeQuery
{
    /** 未过期且具备 HTTP-01 内容的挑战（与 challenges-synced / acme-challenges 一致）。 */
    public static function ready(): Builder
    {
        return RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->whereNotNull('token')
            ->where('token', '!=', '')
            ->whereNotNull('response_body')
            ->where('response_body', '!=', '');
    }
}
