#!/usr/bin/env bash
# 在主控机上：仅允许某节点公网 IP 访问本机 3306（需 firewalld）。
# 用法: sudo ./firewalld-allow-mysql-from-node.sh <节点公网IP>
set -euo pipefail

NODE_IP="${1:-}"
if [[ -z "$NODE_IP" ]]; then
  echo "用法: $0 <节点公网IP>" >&2
  exit 1
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行" >&2
  exit 1
fi

if ! command -v firewall-cmd &>/dev/null; then
  echo "未找到 firewall-cmd，请自行用 iptables 限制 3306 仅来自 $NODE_IP" >&2
  exit 1
fi

RULE="rule family=ipv4 source address=${NODE_IP} port port=3306 protocol=tcp accept"
if ! firewall-cmd --permanent --query-rich-rule="${RULE}" >/dev/null; then
  firewall-cmd --permanent --add-rich-rule="${RULE}"
fi
firewall-cmd --reload
echo "已允许 ${NODE_IP} -> 本机 3306"
