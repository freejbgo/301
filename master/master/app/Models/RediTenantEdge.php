<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RediTenantEdge extends Model
{
    protected $table = 'redi_tenant_edge';

    protected $fillable = [
        'token',
        'edge_fqdn',
        'dns_synced_at',
    ];

    protected $guarded = [
        'admin_id',
    ];

    protected $casts = [
        'dns_synced_at' => 'datetime',
    ];
}
