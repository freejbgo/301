<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::table('redi_admin_domain', function (Blueprint $table) {
            $table->dropUnique('uniq_admin_original_url');
            $table->unique('original_url', 'uniq_original_url_global');
        });
    }

    public function down(): void
    {
        Schema::table('redi_admin_domain', function (Blueprint $table) {
            $table->dropUnique('uniq_original_url_global');
            $table->unique(['admin_id', 'original_url'], 'uniq_admin_original_url');
        });
    }
};
