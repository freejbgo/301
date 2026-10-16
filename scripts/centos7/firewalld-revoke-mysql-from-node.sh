#!/usr/bin/env bash
# 在主控机上：撤销某节点公网 IP 访问本机 3306 的 firewalld 规则。
# 用法: sudo bash scripts/centos7/firewalld-revoke-mysql-from-node.sh <节点公网IP>
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

NODE_IP="${1:-}"
if [[ -z "$NODE_IP" ]]; then
  echo "用法: sudo bash scripts/centos7/firewalld-revoke-mysql-from-node.sh <节点公网IP>" >&2
  exit 1
fi
redi_is_ipv4 "$NODE_IP" || {
  echo "节点 IP 必须是 IPv4: $NODE_IP" >&2
  exit 1
}

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行: sudo bash scripts/centos7/firewalld-revoke-mysql-from-node.sh <节点公网IP>" >&2
  exit 1
fi

if ! command -v firewall-cmd &>/dev/null; then
  exit 0
fi
if ! firewall-cmd --state 2>/dev/null | grep -qx running; then
  echo "错误: firewalld 未运行。请 systemctl start firewalld 后重试。" >&2
  exit 1
fi

RULE="rule family=ipv4 source address=${NODE_IP} port port=3306 protocol=tcp accept"
if firewall-cmd --permanent --query-rich-rule="${RULE}" >/dev/null 2>&1; then
  firewall-cmd --permanent --remove-rich-rule="${RULE}"
  firewall-cmd --reload
  echo "已撤销 ${NODE_IP} -> 本机 3306"
fi
