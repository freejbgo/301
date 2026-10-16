#!/usr/bin/env bash
# 节点 lagran / NFQUEUE 自检（CentOS 7）。不修改系统。
# 用法: bash scripts/centos7/lagran-diagnose.sh [/opt/redi-multi/redi-multi]
set -euo pipefail

APP="${1:-/opt/redi-multi/redi-multi}"
BIN="${APP}/bin/redi-multi"
CFG="${APP}/config.json"
ENV_FILE="${APP}/env.sh"

echo "==> APP=${APP}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE" 2>/dev/null || true
fi

if [[ "${REDI_LAGRAN_DISABLED:-}" == "1" ]]; then
  echo "[env] REDI_LAGRAN_DISABLED=1 → 进程应跳过 lagran"
fi

if [[ -f "$CFG" ]] && command -v python3 >/dev/null 2>&1; then
  python3 - "$CFG" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as f:
    data = json.load(f)
lag = data.get("lagran")
if lag is None:
    print("[config] config.json 未配置 lagran 节 → 默认 enabled=true")
elif lag.get("enabled") is False:
    print("[config] lagran.enabled=false")
else:
    port = lag.get("port", "(默认合并 addr/tls)")
    print(f"[config] lagran.enabled 未关闭, port={port}")
PY
else
  echo "[config] 未找到 ${CFG} 或无 python3"
fi

if [[ -x "$BIN" ]]; then
  if getcap "$BIN" 2>/dev/null | grep -q cap_net_admin; then
    echo "[cap] cap_net_admin 已设置"
  else
    echo "[cap] 缺少 cap_net_admin（lagran 需要 root 或 setcap）"
  fi
else
  echo "[cap] 未找到二进制 ${BIN}"
fi

if lsmod 2>/dev/null | grep -q nfnetlink_queue; then
  echo "[kernel] nfnetlink_queue 已加载"
else
  echo "[kernel] nfnetlink_queue 未加载（modprobe nfnetlink_queue）"
fi

if command -v iptables >/dev/null 2>&1; then
  echo "==> iptables OUTPUT 中 NFQUEUE（redi-multi lagran）"
  iptables -L OUTPUT -n -v 2>/dev/null | grep -E 'NFQUEUE|queue-balance' || echo "(无匹配规则)"
else
  echo "[iptables] 未安装 iptables"
fi

if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files redi-multi.service &>/dev/null; then
  echo "==> systemctl is-active redi-multi: $(systemctl is-active redi-multi 2>/dev/null || echo unknown)"
  echo "==> 最近 journal 含 lagran 的行"
  journalctl -u redi-multi -n 30 --no-pager 2>/dev/null | grep -i lagran || echo "(无)"
fi

echo "完成。清理残留 iptables 规则: sudo bash scripts/centos7/lagran-cleanup-iptables.sh ${APP}"
