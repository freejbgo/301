<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddEdgePoolAdminMenu extends Migration
{
    public function up()
    {
        if (DB::table('admin_menu')->where('id', 12)->exists()) {
            return;
        }

        $now = now();

        DB::table('admin_menu')->insert([
            [
                'id' => 12,
                'parent_id' => 8,
                'order' => 6,
                'title' => 'DNS与节点池',
                'icon' => 'fa-globe',
                'uri' => 'platform-dns',
                'extension' => '',
                'show' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 13,
                'parent_id' => 8,
                'order' => 7,
                'title' => '节点管理',
                'icon' => 'fa-server',
                'uri' => 'nodes',
                'extension' => '',
                'show' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        foreach ([11 => 12, 12 => 13] as $permId => $menuId) {
            if (! DB::table('admin_permissions')->where('id', $permId)->exists()) {
                DB::table('admin_permissions')->insert([
                    'id' => $permId,
                    'name' => $permId === 11 ? 'Platform DNS' : 'Nodes',
                    'slug' => $permId === 11 ? 'platform-dns' : 'nodes',
                    'http_method' => '',
                    'http_path' => $permId === 11 ? '/platform-dns*' : '/nodes*',
                    'order' => $permId,
                    'parent_id' => 7,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
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
    }

    public function down()
    {
        DB::table('admin_role_permissions')->whereIn('permission_id', [11, 12])->delete();
        DB::table('admin_role_menu')->whereIn('menu_id', [12, 13])->delete();
        DB::table('admin_permission_menu')->whereIn('menu_id', [12, 13])->delete();
        DB::table('admin_permissions')->whereIn('id', [11, 12])->delete();
        DB::table('admin_menu')->whereIn('id', [12, 13])->delete();
    }
}
