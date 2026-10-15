#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
VARS_FILE="${VARS_FILE:-${SCRIPT_DIR}/vars.env}"

[[ -f "$VARS_FILE" ]] || { echo "缺少 vars.env" >&2; exit 1; }
# shellcheck disable=SC1090
source "$VARS_FILE"
[[ -n "${MYSQL_ROOT_PASS:-}" && -n "${DB_PASS:-}" && -n "${NODE_IPS:-}" ]] || {
  echo "vars.env 需 MYSQL_ROOT_PASS、DB_PASS、NODE_IPS" >&2
  exit 1
}
redi_sync_node_ips_access "${SCRIPT_DIR}" "$NODE_IPS" "$DB_PASS" "$MYSQL_ROOT_PASS"
echo "完成。NODE_IPS: ${NODE_IPS}"
