<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveAdminExtensionsMenu extends Migration
{
    public function up()
    {
        if (Schema::hasTable('admin_menu')) {
            $menuIds = DB::table('admin_menu')
                ->whereIn('uri', ['auth/extensions', '/auth/extensions'])
                ->pluck('id');

            if ($menuIds->isNotEmpty()) {
                if (Schema::hasTable('admin_role_menu')) {
                    DB::table('admin_role_menu')->whereIn('menu_id', $menuIds)->delete();
                }
                if (Schema::hasTable('admin_permission_menu')) {
                    DB::table('admin_permission_menu')->whereIn('menu_id', $menuIds)->delete();
                }
                DB::table('admin_menu')->whereIn('id', $menuIds)->delete();
            }
        }

        if (! Schema::hasTable('admin_permissions')) {
            return;
        }

        $permissionIds = DB::table('admin_permissions')
            ->where('slug', 'extension')
            ->orWhere('http_path', 'like', '%auth/extensions%')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('admin_role_permissions')) {
            DB::table('admin_role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }
        if (Schema::hasTable('admin_permission_menu')) {
            DB::table('admin_permission_menu')->whereIn('permission_id', $permissionIds)->delete();
        }
        DB::table('admin_permissions')->whereIn('id', $permissionIds)->delete();
    }

    public function down()
    {
        // The extensions screen stays removed.
    }
}
