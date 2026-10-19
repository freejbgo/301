<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNodeIpv6Columns extends Migration
{
    public function up()
    {
        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->string('public_ipv6', 45)->nullable()->after('public_ip');
            $table->text('extra_ipv6')->nullable()->after('extra_ips');
        });
    }

    public function down()
    {
        Schema::table('redi_nodes', function (Blueprint $table) {
            $table->dropColumn(['public_ipv6', 'extra_ipv6']);
        });
    }
}
