<?php

namespace App\Support;

use Dcat\Admin\Admin;
use Illuminate\Support\Facades\DB;

class AdminTenantContext
{
    public static function isPlatformAdministrator(): bool
    {
        $user = Admin::user();

        return $user
            && method_exists($user, 'isAdministrator')
            && $user->isAdministrator();
    }

    public static function sessionAdminId(): int
    {
        return (int) Admin::user()->id;
    }

    /**
     * @return array<int, string> id => label
     */
    public static function groupSelectOptions(?int $restrictToAdminId = null): array
    {
        $query = DB::table('redi_admin_group')->where('status', 1)->orderBy('id');

        if ($restrictToAdminId !== null) {
            $query->where('admin_id', $restrictToAdminId);
        } elseif (! self::isPlatformAdministrator()) {
            $query->where('admin_id', self::sessionAdminId());
        }

        $columns = self::isPlatformAdministrator() && $restrictToAdminId === null
            ? ['id', 'name', 'admin_name']
            : ['id', 'name'];

        $options = [];
        foreach ($query->get($columns) as $row) {
            $label = $row->name;
            if (isset($row->admin_name) && $row->admin_name !== '') {
                $label .= ' ('.$row->admin_name.')';
            }
            $options[(int) $row->id] = $label;
        }

        return $options;
    }

    /**
     * 超级管理员新建分组时选择租户账号（排除 administrator 角色用户）。
     *
     * @return array<int, string>
     */
    public static function tenantOwnerSelectOptions(): array
    {
        $usersTable = (string) config('admin.database.users_table', 'admin_users');
        $rolesTable = (string) config('admin.database.roles_table', 'admin_roles');
        $roleUsersTable = (string) config('admin.database.role_users_table', 'admin_role_users');

        $adminRoleId = DB::table($rolesTable)->where('slug', 'administrator')->value('id');
        $excludeIds = [];
        if ($adminRoleId) {
            $excludeIds = DB::table($roleUsersTable)
                ->where('role_id', $adminRoleId)
                ->pluck('user_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        }

        $query = DB::table($usersTable)->orderBy('id');
        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        $options = [];
        foreach ($query->get(['id', 'username', 'name']) as $row) {
            $label = $row->username;
            if (! empty($row->name) && $row->name !== $row->username) {
                $label .= ' ('.$row->name.')';
            }
            $options[(int) $row->id] = $label;
        }

        return $options;
    }
}
