<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddLagranAdminMenu extends Migration
{
    public function up()
    {
        if (DB::table('admin_menu')->where('id', 14)->exists()) {
            return;
        }

        $now = now();
        DB::table('admin_menu')->insert([
            'id' => 14,
            'parent_id' => 8,
            'order' => 8,
            'title' => 'lagran',
            'icon' => 'fa-sliders',
            'uri' => 'lagran',
            'extension' => '',
            'show' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! DB::table('admin_permissions')->where('id', 13)->exists()) {
            DB::table('admin_permissions')->insert([
                'id' => 13,
                'name' => 'Lagran',
                'slug' => 'lagran',
                'http_method' => '',
                'http_path' => '/lagran*',
                'order' => 13,
                'parent_id' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('admin_permission_menu')->insertOrIgnore([
            'permission_id' => 13,
            'menu_id' => 14,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_role_menu')->insertOrIgnore([
            'role_id' => 1,
            'menu_id' => 14,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_role_permissions')->insertOrIgnore([
            'role_id' => 1,
            'permission_id' => 13,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down()
    {
        DB::table('admin_role_permissions')->where('permission_id', 13)->delete();
        DB::table('admin_role_menu')->where('menu_id', 14)->delete();
        DB::table('admin_permission_menu')->where('menu_id', 14)->delete();
        DB::table('admin_permissions')->where('id', 13)->delete();
        DB::table('admin_menu')->where('id', 14)->delete();
    }
}
