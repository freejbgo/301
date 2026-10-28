#!/usr/bin/env bash
# 为单个节点公网 IP 创建/更新 redi_node 库账号（仅可执行 redi_node_bump_skip，不能 SELECT 全表/视图）。
# 用法: sudo MYSQL_ROOT_PASSWORD='root密码' bash scripts/centos7/grant-node-mysql.sh <节点公网IP> <redi_admin库账号密码>
# 由 grant-nodes/deploy 批量同步时设 REDI_GRANT_BATCH=1（见 lib.sh），不打印单 IP firewalld 提示。
# CentOS 7 请先用 deploy-master.sh 安装 MariaDB 10.6。5.5 没有 CREATE USER IF NOT EXISTS，也吃不下这份 utf8mb4 库。
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行: sudo MYSQL_ROOT_PASSWORD='...' bash scripts/centos7/grant-node-mysql.sh ..." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

NODE_IP="${1:-}"
DB_PASS="${2:-}"

if [[ -z "$NODE_IP" || -z "$DB_PASS" ]]; then
  echo "用法: sudo MYSQL_ROOT_PASSWORD='...' bash scripts/centos7/grant-node-mysql.sh <节点公网IP> <与主控相同的 DB 密码>" >&2
  exit 1
fi

redi_is_ipv4 "$NODE_IP" || {
  echo "节点标识必须是 IPv4（启用 skip-name-resolve 后不能用主机名）: $NODE_IP" >&2
  exit 1
}

ROOT_PASS="${MYSQL_ROOT_PASSWORD:-${MYSQL_ROOT_PASS:-}}"
if [[ -z "$ROOT_PASS" ]]; then
  echo "请设置环境变量 MYSQL_ROOT_PASSWORD（或 vars.env 中的 MYSQL_ROOT_PASS）" >&2
  exit 1
fi

sql_pass=${DB_PASS//\'/\'\'}
sqlfile=$(mktemp)
trap 'rm -f "$sqlfile"' EXIT
{
  printf "CREATE USER IF NOT EXISTS 'redi_node'@'%s' IDENTIFIED BY '%s';\n" "$NODE_IP" "$sql_pass"
  printf "ALTER USER 'redi_node'@'%s' IDENTIFIED BY '%s';\n" "$NODE_IP" "$sql_pass"
  printf "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'redi_node'@'%s';\n" "$NODE_IP"
  printf "GRANT EXECUTE ON PROCEDURE \`redi_admin\`.\`redi_node_bump_skip\` TO 'redi_node'@'%s';\n" "$NODE_IP"
  printf "DROP USER IF EXISTS 'redi_admin'@'%s';\n" "$NODE_IP"
  printf "FLUSH PRIVILEGES;\n"
} > "$sqlfile"

mysql_run() {
  if [[ -f /root/.redi-mysql.cnf ]] \
    && mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
    mysql --defaults-extra-file=/root/.redi-mysql.cnf "$@"
  else
    cnf=$(mktemp)
    chmod 600 "$cnf"
    printf '[client]\nuser=root\npassword=%s\n' "$ROOT_PASS" > "$cnf"
    mysql --defaults-extra-file="$cnf" "$@"
    rm -f "$cnf"
  fi
}

mysql_run < "$sqlfile"
echo "已授权 redi_node@'${NODE_IP}'（仅 EXECUTE redi_node_bump_skip）"
if [[ -z "${REDI_GRANT_BATCH:-}" ]]; then
  echo "若尚未放行主控 firewalld 3306: sudo bash scripts/centos7/firewalld-allow-mysql-from-node.sh ${NODE_IP}"
fi
