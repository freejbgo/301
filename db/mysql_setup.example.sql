-- 在 MySQL 上执行（请修改密码；勿对公网开放 3306）
-- 应用账号仅创建 127.0.0.1；节点远程账号用 grant-node-mysql.sh，勿给整库权限
-- 导入结构数据：mysql -u root -p redi_admin < db/redi_admin.sql
-- 结构数据：mysql -u root -p redi_admin < db/redi_admin.sql（含 redi_node_bump_skip；见 docs/GREENFIELD-INSTALL.md）
--
-- 主控 CentOS 7 请先执行（关闭 DNS 反查，无需手改 my.cnf）：
--   sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh
-- 每个节点公网 IP 授权（不能用主机名）：
--   sudo MYSQL_ROOT_PASSWORD='...' bash scripts/centos7/grant-node-mysql.sh <节点IP> <同上密码>

CREATE DATABASE IF NOT EXISTS `redi_admin` DEFAULT CHARACTER SET utf8mb4;

CREATE USER IF NOT EXISTS 'redi_admin'@'127.0.0.1' IDENTIFIED BY 'change_this_db_password';
CREATE USER IF NOT EXISTS 'redi_admin'@'localhost' IDENTIFIED BY 'change_this_db_password';

-- 含 migrate 所需 DDL/存储过程（日常 DML + 100009 等迁移）
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON `redi_admin`.* TO 'redi_admin'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON `redi_admin`.* TO 'redi_admin'@'localhost';
-- 节点请用 grant-node-mysql.sh（仅 EXECUTE redi_node_bump_skip），勿给整库或 SELECT 域名表权限
-- GRANT ... TO 'redi_admin'@'<节点公网IP>';

FLUSH PRIVILEGES;
