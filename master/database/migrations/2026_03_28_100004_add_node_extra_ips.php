<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNodeExtraIps extends Migration
{
    public function up()
    {
        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->text('extra_ips')->nullable()->after('public_ip');
        });
    }

    public function down()
    {
        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->dropColumn('extra_ips');
        });
    }
}
