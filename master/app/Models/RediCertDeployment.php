<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RediCertDeployment extends Model
{
    protected $table = 'redi_cert_deployments';

    protected $fillable = [
        'cert_id',
        'node_id',
        'status',
        'last_error',
        'deployed_at',
    ];

    protected $casts = [
        'deployed_at' => 'datetime',
    ];
}
