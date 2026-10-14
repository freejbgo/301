<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('redi_tenant_edge')) {
            return;
        }

        DB::table('redi_tenant_edge')->where('admin_id', 0)->delete();
    }

    public function down(): void
    {
        // no-op
    }
};
