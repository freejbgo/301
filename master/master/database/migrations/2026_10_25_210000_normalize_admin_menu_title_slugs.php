<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizeAdminMenuTitleSlugs extends Migration
{
    /** @var array<int, string> */
    private const TITLE_BY_ID = [
        8 => 'site-config',
        9 => 'group',
        10 => 'domain',
        11 => 'platform-settings',
        12 => 'platform-dns',
        13 => 'nodes',
    ];

    public function up()
    {
        if (! Schema::hasTable('admin_menu')) {
            return;
        }

        foreach (self::TITLE_BY_ID as $id => $title) {
            DB::table('admin_menu')->where('id', $id)->update(['title' => $title]);
        }
    }

    public function down()
    {
        // Irreversible: legacy Chinese titles are not restored.
    }
}
