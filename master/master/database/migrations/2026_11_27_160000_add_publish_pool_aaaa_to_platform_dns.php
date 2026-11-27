<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPublishPoolAaaaToPlatformDns extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('redi_platform_dns') || Schema::hasColumn('redi_platform_dns', 'publish_pool_aaaa')) {
            return;
        }

        Schema::table('redi_platform_dns', function (Blueprint $table) {
            $table->boolean('publish_pool_aaaa')->default(true)->after('acme_staging');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('redi_platform_dns') || ! Schema::hasColumn('redi_platform_dns', 'publish_pool_aaaa')) {
            return;
        }

        Schema::table('redi_platform_dns', function (Blueprint $table) {
            $table->dropColumn('publish_pool_aaaa');
        });
    }
}
