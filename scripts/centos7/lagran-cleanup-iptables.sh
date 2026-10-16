#!/usr/bin/env bash
# 删除 redi-multi lagran 写入的 OUTPUT NFQUEUE 规则（进程被 kill -9 等遗留时）。
# 用法: sudo bash scripts/centos7/lagran-cleanup-iptables.sh [/opt/redi-multi/redi-multi]
# 建议先: systemctl stop redi-multi
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="${1:-/opt/redi-multi/redi-multi}"
CFG="${APP}/config.json"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行" >&2
  exit 1
fi

command -v iptables >/dev/null 2>&1 || { echo "需要 iptables" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "需要 python3" >&2; exit 1; }
[[ -f "$CFG" ]] || { echo "找不到 ${CFG}" >&2; exit 1; }

read -r SPORT SYN_ACK ACK PSH_ACK FIN_ACK < <(python3 "${SCRIPT_DIR}/lagran_ports.py" "$CFG")

echo "lagran.port=${SPORT}（与 addr/tls_addr 合并后）"

delete_rule() {
  local flags="$1" queue="$2"
  while iptables -D filter OUTPUT -p tcp -m multiport --sport "$SPORT" \
    --tcp-flags SYN,RST,ACK,FIN,PSH "$flags" \
    -j NFQUEUE --queue-balance "$queue" --queue-bypass 2>/dev/null; do
    echo "已删除: tcp sport ${SPORT} ${flags} → ${queue}"
  done
}

[[ "$SYN_ACK" == "1" ]] && delete_rule "SYN,ACK" "1000:1127"
[[ "$ACK" == "1" ]] && delete_rule "ACK" "2000:2127"
[[ "$PSH_ACK" == "1" ]] && delete_rule "PSH,ACK" "3000:3127"
[[ "$FIN_ACK" == "1" ]] && delete_rule "FIN,ACK" "4000:4127"

echo "完成。可 systemctl start redi-multi 由进程重新 AppendUnique 规则。"
