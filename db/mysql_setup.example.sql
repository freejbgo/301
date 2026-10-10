-- 在 MySQL 上执行（请修改密码；勿对公网开放 3306）
-- 导入结构数据：mysql -u root -p redi_admin < db/redi_admin.sql

CREATE DATABASE IF NOT EXISTS `redi_admin` DEFAULT CHARACTER SET utf8mb4;

CREATE USER IF NOT EXISTS 'redi_admin'@'127.0.0.1' IDENTIFIED BY 'change_this_db_password';
CREATE USER IF NOT EXISTS 'redi_admin'@'10.%' IDENTIFIED BY 'change_this_db_password';

GRANT SELECT, INSERT, UPDATE, DELETE ON `redi_admin`.* TO 'redi_admin'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `redi_admin`.* TO 'redi_admin'@'10.%';

FLUSH PRIVILEGES;
