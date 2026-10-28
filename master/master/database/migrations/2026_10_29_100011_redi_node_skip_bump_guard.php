<?php

// 已上线库：100009 已执行时通过本迁移更新 redi_node_bump_skip（仅合法 http(s) skip_url 才递增 skip_num）。

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS `redi_node_bump_skip`');

        DB::unprepared(
            'CREATE PROCEDURE `redi_node_bump_skip`(IN p_host VARCHAR(255)) '
            .'READS SQL DATA MODIFIES SQL DATA SQL SECURITY DEFINER '
            .'COMMENT \'Edge node: return skip_url for one host and increment skip_num\' '
            .'BEGIN '
            .'DECLARE v_skip VARCHAR(255) DEFAULT NULL; '
            .'START TRANSACTION; '
            .'SELECT `skip_url` INTO v_skip FROM `redi_admin_domain` '
            .'WHERE `original_url` = p_host AND `status` = 1 LIMIT 1 FOR UPDATE; '
            .'IF v_skip IS NOT NULL AND CHAR_LENGTH(TRIM(v_skip)) > 0 AND LOCATE(\'@\', v_skip) = 0 AND ((LOWER(TRIM(v_skip)) LIKE \'http://%\' AND CHAR_LENGTH(TRIM(v_skip)) > 7) OR (LOWER(TRIM(v_skip)) LIKE \'https://%\' AND CHAR_LENGTH(TRIM(v_skip)) > 8)) THEN '
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
        // 不回滚到旧过程体；如需还原请重新部署 db/redi_admin.sql 中的定义。
    }
};
