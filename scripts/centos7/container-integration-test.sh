#!/usr/bin/env bash
# 在 CentOS 7 容器内做集成冒烟（宿主机用 podman 启动容器后 exec 本脚本）。
# 完整 systemd/firewalld 仍建议在真实 CentOS 7 虚拟机验证。
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOG="${LOG:-/tmp/redi-c7-integration.log}"

exec > >(tee -a "$LOG") 2>&1

echo "===== $(date -Is) CentOS 7 integration smoke ====="
# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "centos" || "${VERSION_ID:-}" != 7* ]]; then
  echo "错误: 需要 CentOS 7 容器内执行，当前 ${ID:-?} ${VERSION_ID:-?}"
  exit 1
fi

echo "[1] 部署脚本语法"
bash -n "${SCRIPT_DIR}/lib.sh"
bash -n "${SCRIPT_DIR}/deploy-master.sh"
bash -n "${SCRIPT_DIR}/deploy-node.sh"
bash -n "${SCRIPT_DIR}/grant-node-mysql.sh"
bash -n "${SCRIPT_DIR}/firewalld-allow-mysql-from-node.sh"
bash -n "${SCRIPT_DIR}/revoke-node-mysql.sh"
bash -n "${SCRIPT_DIR}/firewalld-revoke-mysql-from-node.sh"

echo "[2] 发布包"
test -s "${REPO_ROOT}/master/master.tar.gz"
test -s "${REPO_ROOT}/node/redi-multi.tar.gz"
master_list=$(tar tzf "${REPO_ROOT}/master/master.tar.gz")
case $'\n'"${master_list}"$'\n' in
  *$'\nmaster/vendor/autoload.php\n'*) ;;
  *) echo "master.tar.gz 缺少 master/vendor/autoload.php"; exit 1 ;;
esac
node_list=$(tar tzf "${REPO_ROOT}/node/redi-multi.tar.gz")
case $'\n'"${node_list}"$'\n' in
  *redi-multi/bin/redi-multi*) ;;
  *) echo "redi-multi.tar.gz 缺少 redi-multi/bin/redi-multi"; exit 1 ;;
esac

tmpdir=$(mktemp -d)
tar xzf "${REPO_ROOT}/node/redi-multi.tar.gz" -C "$tmpdir"
if ldd "${tmpdir}/redi-multi/bin/redi-multi" 2>&1 | grep -qE 'GLIBC|libc\.so'; then
  echo "节点二进制仍动态链接 libc"
  exit 1
fi
rm -rf "$tmpdir"
echo "节点二进制: 静态链接 OK"

echo "[3] yum 源与关键包（与 deploy-master 相同仓库）"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
redi_use_centos7_vault_repos
yum install -y epel-release yum-utils
redi_use_epel_archive_repo
redi_install_remi_release_7
yum-config-manager --enable remi-php80
rpm --import https://archive.mariadb.org/PublicKey
cat > /etc/yum.repos.d/mariadb.repo <<'EOF'
[mariadb]
name = MariaDB 10.6
baseurl = https://archive.mariadb.org/yum/10.6/centos7-amd64
gpgkey = https://archive.mariadb.org/PublicKey
gpgcheck = 1
enabled = 1
EOF
yum install -y nginx mariadb-server MariaDB-client MariaDB-common MariaDB-compat \
  php php-cli php-fpm php-mysqlnd php-mbstring php-xml php-pecl-zip \
  php-gd php-bcmath php-opcache php-process tar
php -r 'if (PHP_VERSION_ID < 70300 || PHP_VERSION_ID >= 80100) { exit(1); } echo PHP_VERSION, "\n";'

echo "[4] MariaDB 手工启动（无 systemd 时）"
mkdir -p /var/run/mysqld
chown mysql:mysql /var/run/mysqld 2>/dev/null || true
if ! mysqladmin ping --silent 2>/dev/null; then
  if command -v mysqld_safe >/dev/null; then
    mysqld_safe --datadir=/var/lib/mysql >/tmp/mysqld-smoke.log 2>&1 &
  else
    mysqld --user=mysql >/tmp/mysqld-smoke.log 2>&1 &
  fi
  for _ in $(seq 1 90); do
    mysqladmin ping --silent 2>/dev/null && break
    sleep 1
  done
fi
mysqladmin ping --silent || { tail -20 /tmp/mysqld-smoke.log; exit 1; }

DB_PASS="SmokeDbPass2026"
mysql -u root -e "CREATE DATABASE IF NOT EXISTS redi_admin DEFAULT CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'redi_admin'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS 'redi_admin'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON redi_admin.* TO 'redi_admin'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON redi_admin.* TO 'redi_admin'@'localhost';
FLUSH PRIVILEGES;" 2>/dev/null || mysql -u root -e "CREATE DATABASE IF NOT EXISTS redi_admin;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON redi_admin.* TO 'redi_admin'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON redi_admin.* TO 'redi_admin'@'localhost' IDENTIFIED BY '${DB_PASS}';
FLUSH PRIVILEGES;"

table_count=$(mysql -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='redi_admin' AND table_name='admin_users'")
if [[ "$table_count" == "0" ]]; then
  mysql -u root redi_admin < "${REPO_ROOT}/db/redi_admin.sql"
fi

echo "[5] Laravel 发布包解压与 migrate"
APP=/opt/301-smoke/master
rm -rf /opt/301-smoke
mkdir -p /opt/301-smoke
tar xzf "${REPO_ROOT}/master/master.tar.gz" -C /opt/301-smoke
# 仓库内 migrations 为权威来源（提交里的 master.tar.gz 可能落后于当前分支）
if [[ -d "${REPO_ROOT}/master/master/database/migrations" ]]; then
  cp -a "${REPO_ROOT}/master/master/database/migrations/." "${APP}/database/migrations/"
fi
cp "${APP}/.env.example" "${APP}/.env"
export MASTER_URL="http://127.0.0.1" DB_PASS ADMIN_HTTPS=false
# shellcheck source=lib.sh
redi_sync_master_env "${APP}/.env"
cd "${APP}"
php artisan key:generate --force
php artisan migrate --force
proc=$(mysql -u root -N -e "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='redi_admin' AND ROUTINE_NAME='redi_node_bump_skip'")
if [[ "$proc" != "1" ]]; then
  echo "migrate 后缺少 redi_node_bump_skip"
  exit 1
fi
php artisan config:clear
php artisan storage:link
routes=$(php artisan route:list --path=api/nodes 2>/dev/null) || exit 1
case "$routes" in
  *nodes/register*) ;;
  *) echo "缺少 api/nodes/register 路由"; exit 1 ;;
esac

echo "[6] 节点包解压"
NODE_ROOT=/opt/redi-multi-smoke
rm -rf "$NODE_ROOT"
mkdir -p "$NODE_ROOT"
tar xzf "${REPO_ROOT}/node/redi-multi.tar.gz" -C "$NODE_ROOT"
test -x "${NODE_ROOT}/redi-multi/bin/redi-multi"

echo "===== 冒烟通过 ====="
