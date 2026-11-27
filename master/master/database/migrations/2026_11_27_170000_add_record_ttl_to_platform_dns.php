<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRecordTtlToPlatformDns extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('redi_platform_dns') || Schema::hasColumn('redi_platform_dns', 'record_ttl')) {
            return;
        }

        Schema::table('redi_platform_dns', function (Blueprint $table) {
            $table->unsignedInteger('record_ttl')->default(600)->after('publish_pool_aaaa');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('redi_platform_dns') || ! Schema::hasColumn('redi_platform_dns', 'record_ttl')) {
            return;
        }

        Schema::table('redi_platform_dns', function (Blueprint $table) {
            $table->dropColumn('record_ttl');
        });
    }
}
