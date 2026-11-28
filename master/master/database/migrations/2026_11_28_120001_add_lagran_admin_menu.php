<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddLagranAdminMenu extends Migration
{
    public function up()
    {
        $now = now();

        $menuId = DB::table('admin_menu')->where('uri', 'lagran')->value('id');
        if (! $menuId) {
            $row = [
                'parent_id' => 8,
                'order' => 8,
                'title' => 'lagran',
                'icon' => 'fa-sliders',
                'uri' => 'lagran',
                'extension' => '',
                'show' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (! DB::table('admin_menu')->where('id', 14)->exists()) {
                $row['id'] = 14;
            }
            DB::table('admin_menu')->insert($row);
            $menuId = DB::table('admin_menu')->where('uri', 'lagran')->value('id');
        }

        $permId = DB::table('admin_permissions')->where('slug', 'lagran')->value('id');
        if (! $permId) {
            $row = [
                'name' => 'Lagran',
                'slug' => 'lagran',
                'http_method' => '',
                'http_path' => '/lagran*',
                'order' => 13,
                'parent_id' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (! DB::table('admin_permissions')->where('id', 13)->exists()) {
                $row['id'] = 13;
            }
            DB::table('admin_permissions')->insert($row);
            $permId = DB::table('admin_permissions')->where('slug', 'lagran')->value('id');
        }

        DB::table('admin_permission_menu')->insertOrIgnore([
            'permission_id' => $permId,
            'menu_id' => $menuId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_role_menu')->insertOrIgnore([
            'role_id' => 1,
            'menu_id' => $menuId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_role_permissions')->insertOrIgnore([
            'role_id' => 1,
            'permission_id' => $permId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down()
    {
        $menuIds = DB::table('admin_menu')->where('uri', 'lagran')->pluck('id');
        $permIds = DB::table('admin_permissions')->where('slug', 'lagran')->pluck('id');
        if ($permIds->isNotEmpty()) {
            DB::table('admin_role_permissions')->whereIn('permission_id', $permIds)->delete();
            DB::table('admin_permission_menu')->whereIn('permission_id', $permIds)->delete();
            DB::table('admin_permissions')->whereIn('id', $permIds)->delete();
        }
        if ($menuIds->isNotEmpty()) {
            DB::table('admin_role_menu')->whereIn('menu_id', $menuIds)->delete();
            DB::table('admin_permission_menu')->whereIn('menu_id', $menuIds)->delete();
            DB::table('admin_menu')->whereIn('id', $menuIds)->delete();
        }
    }
}
