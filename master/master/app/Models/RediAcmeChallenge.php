<?php

namespace App\Models;

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
        return static::updateOrCreate(
            ['domain_name' => strtolower($domainName)],
            [
                'token' => $token,
                'response_body' => $responseBody,
                'expires_at' => $expiresAt,
            ]
        );
    }
}
