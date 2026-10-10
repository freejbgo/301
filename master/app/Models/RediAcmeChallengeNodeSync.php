<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RediAcmeChallengeNodeSync extends Model
{
    protected $table = 'redi_acme_challenge_node_sync';

    protected $fillable = [
        'node_id',
        'domain_name',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}
