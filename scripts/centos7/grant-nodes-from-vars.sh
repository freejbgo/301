#!/usr/bin/env bash
# 在主控上：按 vars.env 里的 NODE_IPS 批量授权并放行 3306
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行: sudo bash $0" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
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

redi_sync_node_ips_access "${SCRIPT_DIR}" "$NODE_IPS" "$DB_PASS" "$MYSQL_ROOT_PASS"
echo "完成。当前 NODE_IPS: ${NODE_IPS}"
