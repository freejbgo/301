<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddPlatformSettingsMenu extends Migration
{
    public function up()
    {
        $exists = DB::table('admin_menu')->where('id', 11)->exists();
        if ($exists) {
            return;
        }

        $now = now();
        DB::table('admin_menu')->insert([
            'id' => 11,
            'parent_id' => 8,
            'order' => 5,
            'title' => 'platform-settings',
            'icon' => 'fa-paint-brush',
            'uri' => 'platform-settings',
            'extension' => '',
            'show' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permExists = DB::table('admin_permissions')->where('id', 10)->exists();
        if (! $permExists) {
            DB::table('admin_permissions')->insert([
                'id' => 10,
                'name' => 'Platform branding',
                'slug' => 'platform-branding',
                'http_method' => '',
                'http_path' => '/platform-settings*',
                'order' => 10,
                'parent_id' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('admin_permission_menu')->insertOrIgnore([
            'permission_id' => 10,
            'menu_id' => 11,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('admin_role_menu')->insertOrIgnore([
            'role_id' => 1,
            'menu_id' => 11,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('admin_role_permissions')->insertOrIgnore([
            'role_id' => 1,
            'permission_id' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down()
    {
        DB::table('admin_role_permissions')->where('permission_id', 10)->delete();
        DB::table('admin_role_menu')->where('menu_id', 11)->delete();
        DB::table('admin_permission_menu')->where('menu_id', 11)->delete();
        DB::table('admin_permissions')->where('id', 10)->delete();
        DB::table('admin_menu')->where('id', 11)->delete();
    }
}
