#!/usr/bin/env bash
# 为单个节点公网 IP 创建/更新 redi_admin 库账号（配合 skip-name-resolve）。
# 用法: sudo MYSQL_ROOT_PASSWORD='root密码' ./grant-node-mysql.sh <节点公网IP> <redi_admin密码>
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

mysql -u root -p"${ROOT_PASS}" <<SQL
CREATE USER IF NOT EXISTS 'redi_admin'@'${NODE_IP}' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`redi_admin\`.* TO 'redi_admin'@'${NODE_IP}';
FLUSH PRIVILEGES;
SQL

echo "已为节点 ${NODE_IP} 授权 redi_admin@'${NODE_IP}'"
