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
}
