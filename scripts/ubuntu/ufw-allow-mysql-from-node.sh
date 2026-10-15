#!/usr/bin/env bash
# 在主控上：仅允许某节点公网 IP 访问本机 3306（ufw）。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

NODE_IP="${1:-}"
[[ -n "$NODE_IP" ]] || { echo "用法: $0 <节点公网IP>" >&2; exit 1; }
redi_is_ipv4 "$NODE_IP" || exit 1
[[ "$(id -u)" -ne 0 ]] && { echo "请用 root 执行" >&2; exit 1; }

if ! command -v ufw >/dev/null; then
  echo "未找到 ufw，请自行限制 3306 仅来自 $NODE_IP" >&2
  exit 1
fi

if ! ufw status numbered | grep -Fq "redi-node-${NODE_IP}"; then
  ufw allow from "${NODE_IP}" to any port 3306 proto tcp comment "redi-node-${NODE_IP}" >/dev/null
fi
echo "已允许 ${NODE_IP} -> 本机 3306（ufw）"
