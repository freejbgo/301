<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RenameLagranAdminLabels extends Migration
{
    public function up()
    {
        $now = now();

        if (Schema::hasTable('admin_menu')) {
            DB::table('admin_menu')->where('uri', 'lagran')->update([
                'title' => '301-settings',
                'uri' => '301-settings',
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('admin_permissions')) {
            DB::table('admin_permissions')->where(function ($query) {
                $query->where('slug', 'lagran')->orWhere('http_path', '/lagran*');
            })->update([
                'name' => '301设置',
                'slug' => '301-settings',
                'http_path' => '/301-settings*',
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        $now = now();

        if (Schema::hasTable('admin_menu')) {
            DB::table('admin_menu')->where('uri', '301-settings')->update([
                'title' => 'lagran',
                'uri' => 'lagran',
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('admin_permissions')) {
            DB::table('admin_permissions')->where('slug', '301-settings')->update([
                'name' => 'Lagran',
                'slug' => 'lagran',
                'http_path' => '/lagran*',
                'updated_at' => $now,
            ]);
        }
    }
}
