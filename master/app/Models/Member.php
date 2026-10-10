<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;


class Member extends Model
{

    use HasDateTimeFormatter;
    protected $table = 'redi_admin_domain';

    public function group()
    {
        return $this->belongsTo(RediAdminGroup::class, 'group_id', 'id');
    }

    protected $fillable = ['admin_id','status','skip_url','original_url','group_id'];
}