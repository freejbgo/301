<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;

use Illuminate\Database\Eloquent\Model;

class RediAdminGroup extends Model
{
	use HasDateTimeFormatter;
    protected $table = 'redi_admin_group';

    protected static function booted(): void
    {
        static::deleting(function (RediAdminGroup $group) {
            $count = RediAdminDomain::query()
                ->where('group_id', $group->id)
                ->where('admin_id', $group->admin_id)
                ->count();

            if ($count > 0) {
                throw new \RuntimeException(
                    "该分组下还有 {$count} 条域名，请先迁移或删除域名后再删分组。"
                );
            }
        });
    }

    public function domains()
    {
        return $this->hasMany(RediAdminDomain::class, 'group_id');
    }

    /** @deprecated Use domains() */
    public function domain()
    {
        return $this->domains();
    }
}
