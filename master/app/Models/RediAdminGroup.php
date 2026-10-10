<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;

use Illuminate\Database\Eloquent\Model;

class RediAdminGroup extends Model
{
	use HasDateTimeFormatter;
    protected $table = 'redi_admin_group';

    public function domain()
    {
        return $this->hasMany(RediAdminDomain::class);
    }
}
