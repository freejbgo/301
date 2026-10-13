#!/usr/bin/env bash
# 撤销单个节点公网 IP 的远程 redi_admin 账号。
# 用法: sudo MYSQL_ROOT_PASSWORD='root密码' ./revoke-node-mysql.sh <节点公网IP>
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

NODE_IP="${1:-}"
if [[ -z "$NODE_IP" ]]; then
  echo "用法: MYSQL_ROOT_PASSWORD='...' $0 <节点公网IP>" >&2
  exit 1
fi
redi_is_ipv4 "$NODE_IP" || {
  echo "节点 IP 必须是 IPv4: $NODE_IP" >&2
  exit 1
}

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
elif [[ -n "${ROOT_PASS}" ]]; then
  cnf=$(mktemp)
  chmod 600 "$cnf"
  printf '[client]\nuser=root\npassword=%s\n' "$ROOT_PASS" > "$cnf"
  mysql --defaults-extra-file="$cnf" < "$sqlfile"
  rm -f "$cnf"
else
  echo "请设置 MYSQL_ROOT_PASSWORD 或配置 /root/.redi-mysql.cnf" >&2
  exit 1
fi
echo "已撤销 redi_admin@'${NODE_IP}'"
