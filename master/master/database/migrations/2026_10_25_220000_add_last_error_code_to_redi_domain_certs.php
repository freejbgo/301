<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLastErrorCodeToRediDomainCerts extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('redi_domain_certs') || Schema::hasColumn('redi_domain_certs', 'last_error_code')) {
            return;
        }

        Schema::table('redi_domain_certs', function (Blueprint $table) {
            $table->string('last_error_code', 64)->nullable()->after('last_error');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('redi_domain_certs') || ! Schema::hasColumn('redi_domain_certs', 'last_error_code')) {
            return;
        }

        Schema::table('redi_domain_certs', function (Blueprint $table) {
            $table->dropColumn('last_error_code');
        });
    }
}
