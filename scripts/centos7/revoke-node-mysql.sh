#!/usr/bin/env bash
# 撤销单个节点公网 IP 的远程 redi_admin 账号。
# 用法: sudo MYSQL_ROOT_PASSWORD='root密码' ./revoke-node-mysql.sh <节点公网IP>
set -euo pipefail

NODE_IP="${1:-}"
if [[ -z "$NODE_IP" ]]; then
  echo "用法: MYSQL_ROOT_PASSWORD='...' $0 <节点公网IP>" >&2
  exit 1
fi

ROOT_PASS="${MYSQL_ROOT_PASSWORD:-}"
if [[ -z "$ROOT_PASS" ]]; then
  echo "请设置环境变量 MYSQL_ROOT_PASSWORD" >&2
  exit 1
fi

sqlfile=$(mktemp)
trap 'rm -f "$sqlfile"' EXIT
printf "DROP USER IF EXISTS 'redi_admin'@'%s';\nFLUSH PRIVILEGES;\n" "$NODE_IP" > "$sqlfile"

if [[ -f /root/.redi-mysql.cnf ]] \
  && mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
  mysql --defaults-extra-file=/root/.redi-mysql.cnf < "$sqlfile"
else
  mysql -u root -p"${ROOT_PASS}" < "$sqlfile"
fi
echo "已撤销 redi_admin@'${NODE_IP}'"
