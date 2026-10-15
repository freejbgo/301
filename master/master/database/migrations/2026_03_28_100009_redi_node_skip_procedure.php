<?php

// 绿场：过程已写入 db/redi_admin.sql，导入 SQL 即具备；本迁移供仅跑 migrate 或旧库补过程时 idempotent 执行。改过程体请与 SQL 同步。

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `redi_node_domain_lookup`');

        DB::unprepared('DROP PROCEDURE IF EXISTS `redi_node_bump_skip`');

        DB::unprepared(
            'CREATE PROCEDURE `redi_node_bump_skip`(IN p_host VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci) '
            .'READS SQL DATA MODIFIES SQL DATA SQL SECURITY DEFINER '
            .'COMMENT \'Edge node: return skip_url for one host and increment skip_num\' '
            .'BEGIN '
            .'DECLARE v_skip VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL; '
            .'START TRANSACTION; '
            .'SELECT `skip_url` INTO v_skip FROM `redi_admin_domain` '
            .'WHERE `original_url` = p_host AND `status` = 1 LIMIT 1 FOR UPDATE; '
            .'IF v_skip IS NOT NULL THEN '
            .'UPDATE `redi_admin_domain` SET `skip_num` = `skip_num` + 1 '
            .'WHERE `original_url` = p_host AND `status` = 1 LIMIT 1; '
            .'END IF; '
            .'COMMIT; '
            .'SELECT v_skip AS `skip_url`; '
            .'END'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `redi_node_bump_skip`');

        DB::statement('CREATE OR REPLACE VIEW `redi_node_domain_lookup` AS '
            .'SELECT `original_url`, `skip_url`, `status` FROM `redi_admin_domain`');
    }
};
