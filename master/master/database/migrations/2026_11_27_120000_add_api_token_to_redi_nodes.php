<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApiTokenToRediNodes extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('redi_nodes') || Schema::hasColumn('redi_nodes', 'api_token')) {
            return;
        }

        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->text('api_token')->nullable()->after('api_token_hash');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('redi_nodes') || ! Schema::hasColumn('redi_nodes', 'api_token')) {
            return;
        }

        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->dropColumn('api_token');
        });
    }
}
