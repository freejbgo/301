<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RediDnsSyncLog extends Model
{
    protected $table = 'redi_dns_sync_logs';

    protected $fillable = [
        'action',
        'status',
        'message',
    ];

    public static function record(string $action, string $status, ?string $message = null): self
    {
        return static::create([
            'action' => $action,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
