<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $path = base_path('../../db/redi_node_bump_skip_procedure.sql');
        if (! is_readable($path)) {
            $path = database_path('../../../db/redi_node_bump_skip_procedure.sql');
        }
        if (! is_readable($path)) {
            throw new RuntimeException('Missing db/redi_node_bump_skip_procedure.sql');
        }
        DB::unprepared((string) file_get_contents($path));
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `redi_node_bump_skip`');
    }
};
