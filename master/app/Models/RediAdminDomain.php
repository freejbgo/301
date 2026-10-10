<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;

use Illuminate\Database\Eloquent\Model;

class RediAdminDomain extends Model
{
	use HasDateTimeFormatter;
    protected $table = 'redi_admin_domain';

    protected $fillable = [
        'group_id',
        'original_url',
        'skip_url',
        'status',
        'admin_id',
        'admin_name',
        'skip_num',
    ];

    public function group()
    {
        return $this->belongsTo(RediAdminGroup::class, 'group_id', 'id');
    }
}
