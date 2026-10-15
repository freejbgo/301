#!/usr/bin/env bash
# Ubuntu：安装 skip-name-resolve 到 MariaDB 配置并重启（幂等）。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="${ROOT}/db/my.cnf.d/redi-skip-name-resolve.cnf"
DEST="/etc/mysql/mariadb.conf.d/99-redi-skip-name-resolve.cnf"

[[ -f "$SRC" ]] || { echo "找不到 $SRC" >&2; exit 1; }
[[ "$(id -u)" -ne 0 ]] && { echo "请用 root 执行" >&2; exit 1; }

mkdir -p /etc/mysql/mariadb.conf.d
install -m 644 "$SRC" "$DEST"
echo "已安装 $DEST"

if systemctl is-active --quiet mariadb 2>/dev/null; then
  systemctl restart mariadb
elif systemctl is-active --quiet mysql 2>/dev/null; then
  systemctl restart mysql
else
  systemctl restart mariadb || systemctl restart mysql
fi
echo "已重启数据库。节点账号请用 IP 授权，见 grant-node-mysql.sh"
