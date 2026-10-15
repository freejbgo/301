#!/usr/bin/env bash
# Ubuntu：安装 skip-name-resolve 到 MariaDB 配置并重启（幂等）。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SRC="${ROOT}/db/my.cnf.d/redi-skip-name-resolve.cnf"
DEST="/etc/mysql/mariadb.conf.d/99-redi-skip-name-resolve.cnf"

[[ -f "$SRC" ]] || { echo "找不到 $SRC" >&2; exit 1; }
[[ "$(id -u)" -ne 0 ]] && { echo "请用 root 执行" >&2; exit 1; }

mkdir -p /etc/mysql/mariadb.conf.d
install -m 644 "$SRC" "$DEST"
echo "已安装 $DEST"

redi_restart mariadb || redi_restart mysql
echo "已重启数据库。节点账号请用 IP 授权，见 grant-node-mysql.sh"
