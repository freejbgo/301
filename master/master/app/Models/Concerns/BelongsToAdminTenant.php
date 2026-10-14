<?php

namespace App\Models\Concerns;

use Dcat\Admin\Admin;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToAdminTenant
{
    public static function bootBelongsToAdminTenant(): void
    {
        static::addGlobalScope('admin_tenant', function (Builder $builder) {
            if (app()->runningInConsole()) {
                return;
            }

            $user = Admin::user();
            if ($user) {
                if (method_exists($user, 'isAdministrator') && $user->isAdministrator()) {
                    return;
                }

                $builder->where(
                    $builder->getModel()->getTable().'.admin_id',
                    (int) $user->id
                );

                return;
            }

            if (request()->is('admin', 'admin/*')) {
                $builder->whereRaw('1 = 0');
            }
        });
    }
}
