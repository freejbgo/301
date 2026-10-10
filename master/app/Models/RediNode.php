<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RediNode extends Model
{
    protected $table = 'redi_nodes';

    protected $fillable = [
        'name',
        'public_ip',
        'enabled',
        'api_token_hash',
        'last_heartbeat_at',
        'agent_version',
        'note',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

    public static function generateApiToken(): string
    {
        return Str::random(48);
    }

    public function issueApiTokenPlain(): string
    {
        $secret = Str::random(48);
        $plain = $this->id.'.'.$secret;
        $this->setApiTokenPlain($plain);

        return $plain;
    }

    public function setApiTokenPlain(string $plain): void
    {
        $this->api_token_hash = Hash::make($plain);
    }

    public function verifyApiToken(string $plain): bool
    {
        return $this->api_token_hash && Hash::check($plain, $this->api_token_hash);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
