<?php

use App\Services\DomainRecordNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redi_admin_domain')) {
            return;
        }

        if (! Schema::hasColumn('redi_admin_domain', 'original_url_hash')) {
            Schema::table('redi_admin_domain', function (Blueprint $table) {
                $table->string('original_url_hash', 64)->nullable()->after('original_url');
            });
        }

        DB::table('redi_admin_domain')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $norm = DomainRecordNormalizer::normalizeOriginalUrl($row->original_url);
                $hash = DomainRecordNormalizer::originalUrlHash($norm);
                if ($hash === '') {
                    continue;
                }
                DB::table('redi_admin_domain')
                    ->where('id', $row->id)
                    ->update(['original_url_hash' => $hash]);
            }
        });

        $dupes = DB::table('redi_admin_domain')
            ->select('original_url_hash', DB::raw('COUNT(*) as c'))
            ->whereNotNull('original_url_hash')
            ->groupBy('original_url_hash')
            ->having('c', '>', 1)
            ->pluck('original_url_hash');

        if ($dupes->isNotEmpty()) {
            throw new \RuntimeException(
                'original_url_hash 冲突，请先合并重复域名：'.implode(', ', $dupes->all())
            );
        }

        if (! $this->indexExists('redi_admin_domain', 'uniq_original_url_hash')) {
            Schema::table('redi_admin_domain', function (Blueprint $table) {
                $table->unique('original_url_hash', 'uniq_original_url_hash');
            });
        }

        DB::statement('CREATE OR REPLACE VIEW `redi_node_domain_lookup` AS '
            .'SELECT `original_url`, `skip_url`, `status` FROM `redi_admin_domain`');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `redi_node_domain_lookup`');

        if (Schema::hasColumn('redi_admin_domain', 'original_url_hash')) {
            Schema::table('redi_admin_domain', function (Blueprint $table) {
                if ($this->indexExists('redi_admin_domain', 'uniq_original_url_hash')) {
                    $table->dropUnique('uniq_original_url_hash');
                }
                $table->dropColumn('original_url_hash');
            });
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
