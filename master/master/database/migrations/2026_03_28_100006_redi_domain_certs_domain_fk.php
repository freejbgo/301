<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redi_domain_certs') || ! Schema::hasTable('redi_admin_domain')) {
            return;
        }

        DB::table('redi_domain_certs')
            ->whereNotIn('domain_id', DB::table('redi_admin_domain')->select('id'))
            ->delete();

        Schema::table('redi_domain_certs', function (Blueprint $table) {
            $table->foreign('domain_id')
                ->references('id')
                ->on('redi_admin_domain')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('redi_domain_certs')) {
            return;
        }

        Schema::table('redi_domain_certs', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
        });
    }
};
