<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminTenant;
use App\Support\OpsDisplaySanitizer;
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
    ];

    protected $guarded = [
        'admin_id',
        'admin_name',
    ];

    protected static function booted(): void
    {
        static::saving(function (RediAdminGroup $group) {
            if (! $group->isDirty('name')) {
                return;
            }
            $name = OpsDisplaySanitizer::stripControlChars((string) $group->name);
            $name = trim($name);
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => '分组名称不能为空',
                ]);
            }
            if (mb_strlen($name) > 120) {
                $name = mb_substr($name, 0, 120);
            }
            $group->name = $name;
        });

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
