#!/usr/bin/env bash
# 为单个节点公网 IP 创建/更新 redi_admin 库账号（配合 skip-name-resolve）。
# 用法: sudo MYSQL_ROOT_PASSWORD='root密码' ./grant-node-mysql.sh <节点公网IP> <redi_admin密码>
# CentOS 7 请先用 deploy-master.sh 安装 MariaDB 10.6。5.5 没有 CREATE USER IF NOT EXISTS，也吃不下这份 utf8mb4 库。
set -euo pipefail

NODE_IP="${1:-}"
DB_PASS="${2:-}"

if [[ -z "$NODE_IP" || -z "$DB_PASS" ]]; then
  echo "用法: MYSQL_ROOT_PASSWORD='...' $0 <节点公网IP> <redi_admin同一密码>" >&2
  exit 1
fi

if [[ ! "$NODE_IP" =~ ^[0-9a-fA-F:.]+$ ]]; then
  echo "节点标识必须是 IP（启用 skip-name-resolve 后不能用主机名）: $NODE_IP" >&2
  exit 1
fi

ROOT_PASS="${MYSQL_ROOT_PASSWORD:-}"
if [[ -z "$ROOT_PASS" ]]; then
  echo "请设置环境变量 MYSQL_ROOT_PASSWORD" >&2
  exit 1
fi

sql_pass=${DB_PASS//\'/\'\'}
sqlfile=$(mktemp)
trap 'rm -f "$sqlfile"' EXIT
{
  printf "CREATE USER IF NOT EXISTS 'redi_admin'@'%s' IDENTIFIED BY '%s';\n" "$NODE_IP" "$sql_pass"
  printf "ALTER USER 'redi_admin'@'%s' IDENTIFIED BY '%s';\n" "$NODE_IP" "$sql_pass"
  printf "GRANT SELECT ON \`redi_admin\`.\`redi_admin_domain\` TO 'redi_admin'@'%s';\n" "$NODE_IP"
  printf "GRANT UPDATE (skip_num) ON \`redi_admin\`.\`redi_admin_domain\` TO 'redi_admin'@'%s';\n" "$NODE_IP"
  printf "FLUSH PRIVILEGES;\n"
} > "$sqlfile"

mysql_run() {
  if [[ -f /root/.redi-mysql.cnf ]] \
    && mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
    mysql --defaults-extra-file=/root/.redi-mysql.cnf "$@"
  else
    mysql -u root -p"${ROOT_PASS}" "$@"
  fi
}

# 收紧旧版「整库 DML」授权；全新用户尚无库级权限时 REVOKE 可能报错，忽略即可。
mysql_run -e "REVOKE ALL PRIVILEGES, GRANT OPTION ON \`redi_admin\`.* FROM 'redi_admin'@'${NODE_IP}';" 2>/dev/null || true
mysql_run < "$sqlfile"
echo "已为节点 ${NODE_IP} 授权 redi_admin@'${NODE_IP}'"
