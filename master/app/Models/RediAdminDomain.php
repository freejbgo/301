<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;

use Illuminate\Database\Eloquent\Model;

class RediAdminDomain extends Model
{
	use HasDateTimeFormatter;
    protected $table = 'redi_admin_domain';

    public function group()
    {
        return $this->belongsTo(RediAdminGroup::class, 'group_id', 'id');
    }
}
