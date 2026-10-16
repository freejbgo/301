<?php

namespace App\Services;

use App\Models\RediAcmeChallenge;
use App\Support\AcmeHttp01Token;
use App\Support\OpsDisplaySanitizer;
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

    public static function http01ContentValid(?string $token, ?string $responseBody): bool
    {
        $body = trim((string) $responseBody);

        return AcmeHttp01Token::isValid((string) $token)
            && $body !== ''
            && ! OpsDisplaySanitizer::containsControlChars($body);
    }
}
