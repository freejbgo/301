<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redi_cert_deployments')) {
            return;
        }

        DB::table('redi_cert_deployments')
            ->whereNotIn('cert_id', DB::table('redi_domain_certs')->select('id'))
            ->delete();
        DB::table('redi_cert_deployments')
            ->whereNotIn('node_id', DB::table('redi_nodes')->select('id'))
            ->delete();

        Schema::table('redi_cert_deployments', function (Blueprint $table) {
            if (! $this->foreignExists('redi_cert_deployments', 'redi_cert_deployments_cert_id_foreign')) {
                $table->foreign('cert_id')
                    ->references('id')
                    ->on('redi_domain_certs')
                    ->onDelete('cascade');
            }
            if (! $this->foreignExists('redi_cert_deployments', 'redi_cert_deployments_node_id_foreign')) {
                $table->foreign('node_id')
                    ->references('id')
                    ->on('redi_nodes')
                    ->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('redi_cert_deployments')) {
            return;
        }

        Schema::table('redi_cert_deployments', function (Blueprint $table) {
            if ($this->foreignExists('redi_cert_deployments', 'redi_cert_deployments_cert_id_foreign')) {
                $table->dropForeign(['cert_id']);
            }
            if ($this->foreignExists('redi_cert_deployments', 'redi_cert_deployments_node_id_foreign')) {
                $table->dropForeign(['node_id']);
            }
        });
    }

    protected function foreignExists(string $table, string $name): bool
    {
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $name]
        );

        return count($rows) > 0;
    }
};
