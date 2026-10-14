<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 业务核心表（绿场安装）。从全量 SQL 导入时本迁移自动跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redi_admin_group')) {
            Schema::create('redi_admin_group', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 50);
                $table->tinyInteger('status')->default(1)->comment('1启用 2停用');
                $table->unsignedInteger('admin_id')->default(0);
                $table->string('admin_name', 50)->default('');
                $table->string('created_at', 50);
                $table->string('updated_at', 50);
                $table->index('admin_id', 'idx_admin');
            });
        }

        if (! Schema::hasTable('redi_admin_domain')) {
            Schema::create('redi_admin_domain', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('group_id')->default(0);
                $table->string('original_url', 255)->default('');
                $table->string('original_url_hash', 64)->nullable();
                $table->string('skip_url', 255)->default('');
                $table->unsignedTinyInteger('status')->default(1);
                $table->boolean('force_https')->default(false);
                $table->unsignedInteger('admin_id')->default(0);
                $table->string('admin_name', 50)->default('');
                $table->unsignedInteger('skip_num')->default(0);
                $table->string('created_at', 50);
                $table->string('updated_at', 50);
                $table->index('group_id', 'idx_group');
                $table->index('original_url', 'idx_original_url');
                $table->index('admin_id', 'idx_admin');
                $table->unique('original_url', 'uniq_original_url_global');
                $table->unique('original_url_hash', 'uniq_original_url_hash');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('redi_admin_domain');
        Schema::dropIfExists('redi_admin_group');
    }
};
