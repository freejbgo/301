<?php

namespace App\Models;

use App\Services\AcmeChallengeCleanup;
use App\Services\AcmeChallengeQuery;
use App\Services\DomainRecordNormalizer;
use App\Support\AcmeHttp01Token;
use Illuminate\Database\Eloquent\Model;

class RediAcmeChallenge extends Model
{
    protected $table = 'redi_acme_challenges';

    protected $fillable = [
        'domain_name',
        'token',
        'response_body',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public static function upsertForDomain(string $domainName, string $token, string $responseBody, \DateTimeInterface $expiresAt): self
    {
        $domainName = DomainRecordNormalizer::acmeHostKey($domainName);
        if ($domainName === '') {
            throw new \InvalidArgumentException('ACME 挑战域名无效');
        }

        $token = AcmeHttp01Token::normalize($token);
        if (! AcmeChallengeQuery::http01ContentValid($token, $responseBody)) {
            throw new \InvalidArgumentException('ACME 挑战响应体无效');
        }
        $responseBody = trim($responseBody);

        AcmeChallengeCleanup::purgeForHosts([$domainName]);

        return static::updateOrCreate(
            ['domain_name' => $domainName],
            [
                'token' => $token,
                'response_body' => $responseBody,
                'expires_at' => $expiresAt,
            ]
        );
    }
}
