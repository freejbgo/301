#!/usr/bin/env bash
# 将 skip-name-resolve 配置安装到 CentOS/RHEL 的 my.cnf.d 并重启数据库（幂等）。
# 用法: sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="${ROOT}/db/my.cnf.d/redi-skip-name-resolve.cnf"
DEST="/etc/my.cnf.d/redi-skip-name-resolve.cnf"

if [[ ! -f "$SRC" ]]; then
  echo "找不到配置: $SRC" >&2
  exit 1
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "请用 root 执行: sudo bash scripts/centos7/apply-mysql-skip-name-resolve.sh" >&2
  exit 1
fi

mkdir -p /etc/my.cnf.d
install -m 644 "$SRC" "$DEST"
echo "已安装 $DEST"

pick_svc() {
  if systemctl list-unit-files mysqld.service &>/dev/null && systemctl is-enabled mysqld &>/dev/null 2>&1; then
    echo mysqld
    return
  fi
  if systemctl list-unit-files mariadb.service &>/dev/null && systemctl is-enabled mariadb &>/dev/null 2>&1; then
    echo mariadb
    return
  fi
  if systemctl status mysqld &>/dev/null; then
    echo mysqld
  else
    echo mariadb
  fi
}

SVC="$(pick_svc)"
if ! systemctl restart "$SVC"; then
  echo "重启 $SVC 失败，请检查: systemctl status $SVC" >&2
  exit 1
fi

echo "已重启 ${SVC}。可用以下命令确认:"
echo "  mysql -e \"SHOW VARIABLES LIKE 'skip_name_resolve';\""
echo "注意: 节点数据库账号请用 IP 授权，见 scripts/centos7/grant-node-mysql.sh"
