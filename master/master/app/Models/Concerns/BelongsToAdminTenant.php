<?php

namespace App\Models\Concerns;

use Dcat\Admin\Admin;
use Illuminate\Database\Eloquent\Builder;

/**
 * 后台 HTTP 请求按 admin_id 过滤；Console / queue:work 不过滤（续期、health 等需跨租户）。
 * 新增 Artisan 命令输出日志或 Webhook 时勿泄露其它租户数据。
 */
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
