<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLocaleToAdminUsers extends Migration
{
    public function up()
    {
        $table = (string) config('admin.database.users_table', 'admin_users');
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'locale')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->string('locale', 12)->nullable()->after('avatar');
        });
    }

    public function down()
    {
        $table = (string) config('admin.database.users_table', 'admin_users');
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'locale')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
}
