<?php

namespace App\Models;

use App\Support\HostNameValidator;
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
        if ($domainName === '') {
            throw new \InvalidArgumentException('ACME 挑战域名无效');
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
