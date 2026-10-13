<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('redi_admin_domain', 'uniq_original_url_global')) {
            return;
        }

        $dupes = DB::table('redi_admin_domain')
            ->select('original_url', DB::raw('COUNT(*) as c'))
            ->groupBy('original_url')
            ->having('c', '>', 1)
            ->pluck('original_url');

        if ($dupes->isNotEmpty()) {
            throw new \RuntimeException(
                '无法添加全局唯一约束：以下 original_url 被多个租户占用，请先合并或删除重复记录：'
                .implode(', ', $dupes->all())
            );
        }

        if ($this->indexExists('redi_admin_domain', 'uniq_admin_original_url')) {
            Schema::table('redi_admin_domain', function (Blueprint $table) {
                $table->dropUnique('uniq_admin_original_url');
            });
        }

        DB::statement(
            'ALTER TABLE `redi_admin_domain` ADD UNIQUE `uniq_original_url_global` (`original_url`(191))'
        );
    }

    public function down(): void
    {
        if (! $this->indexExists('redi_admin_domain', 'uniq_original_url_global')) {
            return;
        }

        Schema::table('redi_admin_domain', function (Blueprint $table) {
            $table->dropUnique('uniq_original_url_global');
        });

        if (! $this->indexExists('redi_admin_domain', 'uniq_admin_original_url')) {
            DB::statement(
                'ALTER TABLE `redi_admin_domain` ADD UNIQUE `uniq_admin_original_url` (`admin_id`, `original_url`(191))'
            );
        }
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?',
            [$indexName]
        );

        return count($rows) > 0;
    }
};
