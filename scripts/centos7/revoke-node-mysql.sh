#!/usr/bin/env bash
# 撤销单个节点公网 IP 的远程 redi_node / 遗留 redi_admin 账号。
# 用法: sudo MYSQL_ROOT_PASSWORD='root密码' bash scripts/centos7/revoke-node-mysql.sh <节点公网IP>
# 由 grant-nodes/deploy 批量同步时设 REDI_GRANT_BATCH=1（见 lib.sh），不打印单 IP firewalld 提示。
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行: sudo MYSQL_ROOT_PASSWORD='...' bash scripts/centos7/revoke-node-mysql.sh ..." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

NODE_IP="${1:-}"
if [[ -z "$NODE_IP" ]]; then
  echo "用法: sudo MYSQL_ROOT_PASSWORD='...' bash scripts/centos7/revoke-node-mysql.sh <节点公网IP>" >&2
  exit 1
fi
redi_is_ipv4 "$NODE_IP" || {
  echo "节点 IP 必须是 IPv4: $NODE_IP" >&2
  exit 1
}

ROOT_PASS="${MYSQL_ROOT_PASSWORD:-${MYSQL_ROOT_PASS:-}}"
if [[ -z "$ROOT_PASS" ]]; then
  echo "请设置环境变量 MYSQL_ROOT_PASSWORD（或 vars.env 中的 MYSQL_ROOT_PASS）" >&2
  exit 1
fi

sqlfile=$(mktemp)
trap 'rm -f "$sqlfile"' EXIT
{
  printf "DROP USER IF EXISTS 'redi_node'@'%s';\n" "$NODE_IP"
  printf "DROP USER IF EXISTS 'redi_admin'@'%s';\n" "$NODE_IP"
  printf "FLUSH PRIVILEGES;\n"
} > "$sqlfile"

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
echo "已撤销 redi_node / redi_admin @'${NODE_IP}'"
if [[ -z "${REDI_GRANT_BATCH:-}" ]]; then
  echo "若需同步撤销 firewalld: sudo bash scripts/centos7/firewalld-revoke-mysql-from-node.sh ${NODE_IP}"
fi
