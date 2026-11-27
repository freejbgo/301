<?php

namespace App\Models;

use App\Exceptions\GroupNotEmptyException;
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
                    'name' => __('redi-admin-group.validation.name_required'),
                ]);
            }
            if (mb_strlen($name) > 120) {
                $name = mb_substr($name, 0, 120);
            }
            $group->name = $name;
        });

        static::deleting(function (RediAdminGroup $group) {
            $reason = $group->deleteBlockedReason();
            if ($reason !== null) {
                throw new GroupNotEmptyException($reason);
            }
        });
    }

    /**
     * Plain-language reason when this group still has domains, or null when it can be deleted.
     */
    public function deleteBlockedReason(): ?string
    {
        $count = (int) RediAdminDomain::query()
            ->where('group_id', $this->id)
            ->count();

        if ($count < 1) {
            return null;
        }

        return __('redi-admin-group.validation.delete_has_domains', ['count' => $count]);
    }

    public function domains()
    {
        return $this->hasMany(RediAdminDomain::class, 'group_id');
    }
}
