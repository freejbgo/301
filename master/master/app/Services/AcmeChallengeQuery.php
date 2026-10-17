<?php

namespace App\Services;

use App\Models\RediAcmeChallenge;
use App\Support\AcmeHttp01Token;
use App\Support\HostNameValidator;
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
        $raw = (string) $responseBody;
        if (OpsDisplaySanitizer::containsControlChars($raw)) {
            return false;
        }
        $body = trim($raw);

        return AcmeHttp01Token::isValid((string) $token) && $body !== '';
    }

    public static function nodeReadyRow(?string $domainName, ?string $token, ?string $responseBody): bool
    {
        $domain = HostNameValidator::toCanonicalAscii((string) $domainName);

        return $domain !== ''
            && HostNameValidator::isValidHostLabel($domain)
            && self::http01ContentValid($token, $responseBody);
    }

    /** 节点 API 可下发的挑战行（含控制字符显式拒绝，与 acme-challenges / challenges-synced 一致）。 */
    public static function nodePublishableRow(?string $domainName, ?string $token, ?string $responseBody): bool
    {
        foreach ([$domainName, $token, $responseBody] as $part) {
            if (OpsDisplaySanitizer::containsControlChars((string) $part)) {
                return false;
            }
        }

        return self::nodeReadyRow($domainName, $token, $responseBody);
    }
}
