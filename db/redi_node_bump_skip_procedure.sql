DROP PROCEDURE IF EXISTS `redi_node_bump_skip`;
DELIMITER ;;
CREATE PROCEDURE `redi_node_bump_skip`(IN p_host VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
    READS SQL DATA
    MODIFIES SQL DATA
    SQL SECURITY DEFINER
    COMMENT 'Edge node: return skip_url for one host and increment skip_num'
BEGIN
    DECLARE v_skip VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

    START TRANSACTION;

    SELECT `skip_url` INTO v_skip
    FROM `redi_admin_domain`
    WHERE `original_url` COLLATE utf8mb4_unicode_ci = (CONVERT(p_host USING utf8mb4) COLLATE utf8mb4_unicode_ci)
      AND `status` = 1
    LIMIT 1
    FOR UPDATE;

    IF v_skip IS NOT NULL THEN
        UPDATE `redi_admin_domain`
        SET `skip_num` = `skip_num` + 1
        WHERE `original_url` COLLATE utf8mb4_unicode_ci = (CONVERT(p_host USING utf8mb4) COLLATE utf8mb4_unicode_ci)
          AND `status` = 1
        LIMIT 1;
    END IF;

    COMMIT;

    SELECT v_skip AS `skip_url`;
END ;;
DELIMITER ;
