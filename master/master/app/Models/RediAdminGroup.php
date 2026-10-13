<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminTenant;
use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RediAdminGroup extends Model
{
    use BelongsToAdminTenant;
    use HasDateTimeFormatter;

    protected $table = 'redi_admin_group';

    protected $fillable = [
        'name',
        'status',
        'admin_id',
        'admin_name',
    ];

    protected static function booted(): void
    {
        static::deleting(function (RediAdminGroup $group) {
            $count = RediAdminDomain::query()
                ->where('group_id', $group->id)
                ->where('admin_id', $group->admin_id)
                ->count();

            if ($count > 0) {
                throw ValidationException::withMessages([
                    'name' => "该分组下还有 {$count} 条域名，请先迁移或删除域名后再删分组。",
                ]);
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
