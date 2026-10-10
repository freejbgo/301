#!/usr/bin/env bash
# 在主控上：按 vars.env 里的 NODE_IPS 批量授权并放行 3306
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VARS_FILE="${VARS_FILE:-${SCRIPT_DIR}/vars.env}"

if [[ ! -f "$VARS_FILE" ]]; then
  echo "请先复制并编辑: cp ${SCRIPT_DIR}/vars.example.env vars.env" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$VARS_FILE"

if [[ -z "${MYSQL_ROOT_PASS:-}" || -z "${DB_PASS:-}" ]]; then
  echo "vars.env 需设置 MYSQL_ROOT_PASS 与 DB_PASS" >&2
  exit 1
fi

if [[ -z "${NODE_IPS:-}" ]]; then
  echo "vars.env 需设置 NODE_IPS（空格分隔）" >&2
  exit 1
fi

export MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

for ip in $NODE_IPS; do
  echo "==> 节点 ${ip}"
  sudo -E bash "${ROOT}/scripts/centos7/grant-node-mysql.sh" "$ip" "$DB_PASS"
  sudo bash "${ROOT}/scripts/centos7/firewalld-allow-mysql-from-node.sh" "$ip"
done

echo "完成。节点数: $(echo "$NODE_IPS" | wc -w)"
