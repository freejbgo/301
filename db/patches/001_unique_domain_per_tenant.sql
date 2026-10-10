-- 每个租户下原始域名唯一（执行前请确认无重复数据）
-- SELECT admin_id, original_url, COUNT(*) c FROM redi_admin_domain GROUP BY admin_id, original_url HAVING c > 1;

ALTER TABLE `redi_admin_domain`
  ADD UNIQUE KEY `uniq_admin_original_url` (`admin_id`, `original_url`(191));
