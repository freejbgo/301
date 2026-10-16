<?php

namespace App\Models;

use App\Support\AcmeHttp01Token;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
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
        $domainName = HostNameValidator::toCanonicalAscii($domainName);
        if ($domainName === '' || ! HostNameValidator::isValidHostLabel($domainName)) {
            throw new \InvalidArgumentException('ACME 挑战域名无效');
        }

        $token = AcmeHttp01Token::normalize($token);
        $responseBody = trim($responseBody);
        if ($responseBody === '' || OpsDisplaySanitizer::containsControlChars($responseBody)) {
            throw new \InvalidArgumentException('ACME 挑战响应体无效');
        }

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
