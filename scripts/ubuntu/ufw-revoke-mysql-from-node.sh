#!/usr/bin/env bash
# 撤销某节点 IP 访问本机 3306 的 ufw 规则。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

NODE_IP="${1:-}"
[[ -n "$NODE_IP" ]] || exit 0
redi_is_ipv4 "$NODE_IP" || exit 0
[[ "$(id -u)" -ne 0 ]] && exit 0
command -v ufw >/dev/null || exit 0

while ufw status numbered | grep -Eq "[[:space:]]${NODE_IP}[[:space:]].*3306"; do
  num=$(ufw status numbered | grep -E "[[:space:]]${NODE_IP}[[:space:]].*3306" | head -1 | sed -E 's/^\[ *([0-9]+)\].*/\1/')
  [[ -n "$num" ]] || break
  yes | ufw delete "$num" >/dev/null 2>&1 || break
done
echo "已尝试撤销 ${NODE_IP} -> 3306 的 ufw 规则"
