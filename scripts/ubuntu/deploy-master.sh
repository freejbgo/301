#!/usr/bin/env bash
# Ubuntu 22.04 / 24.04 LTS 主控一键部署。
#
#   cp scripts/ubuntu/vars.example.env scripts/ubuntu/vars.env
#   chmod 600 scripts/ubuntu/vars.env && vi scripts/ubuntu/vars.env
#   sudo bash scripts/ubuntu/deploy-master.sh
#
# 不要把 git 仓库克隆到 MASTER_INSTALL_ROOT（默认 /opt/301）。重复执行安全。
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
VARS_FILE="${VARS_FILE:-${SCRIPT_DIR}/vars.env}"

die() { echo "错误: $*" >&2; exit 1; }
step() { echo ""; echo "==> $*"; }

[[ "$(id -u)" -ne 0 ]] && die "请用 root 执行: sudo bash $0"
[[ -f /etc/os-release ]] || die "找不到 /etc/os-release"
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "此脚本仅用于 Ubuntu，当前: ${ID:-unknown}"
PHP_DOT="$(redi_ubuntu_php_version "${VERSION_ID:-}")" || exit 1
WEB_USER="$(redi_ubuntu_web_user)"
FPM_SOCK="/run/php/php${PHP_DOT}-fpm.sock"

[[ -f "$VARS_FILE" ]] || die "请先复制 vars.example.env 为 vars.env"
# shellcheck disable=SC1090
source "$VARS_FILE"

TAR="${REDI_MASTER_TAR:-${REPO_ROOT}/master/master.tar.gz}"

: "${MASTER_IP:?vars.env 需要 MASTER_IP}"
: "${DB_PASS:?vars.env 需要 DB_PASS}"
: "${MYSQL_ROOT_PASS:?vars.env 需要 MYSQL_ROOT_PASS}"
: "${MASTER_INSTALL_ROOT:?vars.env 需要 MASTER_INSTALL_ROOT}"
: "${MASTER_URL:?vars.env 需要 MASTER_URL}"
: "${ADMIN_HTTPS:?vars.env 需要 ADMIN_HTTPS}"
NODE_IPS="${NODE_IPS:-}"

reject_text() {
  local name="$1" value="$2"
  if [[ "$value" == *$'\n'* || "$value" =~ [[:space:]\'\"\`\#\$\\] ]]; then
    die "${name} 不要包含空格、引号、反引号、\$、# 或反斜杠"
  fi
}
is_ipv4() { redi_is_ipv4 "$1"; }

export MASTER_IP DB_PASS MYSQL_ROOT_PASS MASTER_URL ADMIN_HTTPS
reject_text "DB_PASS" "$DB_PASS"
reject_text "MYSQL_ROOT_PASS" "$MYSQL_ROOT_PASS"
reject_text "MASTER_URL" "$MASTER_URL"
reject_text "MASTER_INSTALL_ROOT" "$MASTER_INSTALL_ROOT"
is_ipv4 "$MASTER_IP" || die "MASTER_IP 必须是 IPv4"
[[ "$MASTER_URL" == http://* || "$MASTER_URL" == https://* ]] || die "MASTER_URL 要以 http:// 或 https:// 开头"
[[ "$ADMIN_HTTPS" == "true" || "$ADMIN_HTTPS" == "false" ]] || die "ADMIN_HTTPS 只能是 true 或 false"
[[ -f "$TAR" ]] || die "找不到主控发布包: $TAR"
[[ -f "${REPO_ROOT}/db/redi_admin.sql" ]] || die "找不到 db/redi_admin.sql"

APP="${MASTER_INSTALL_ROOT}/master"
[[ -d "${APP}/master" && -f "${APP}/master/artisan" ]] && die "请勿把仓库克隆到 ${APP}"

step "Ubuntu ${VERSION_ID}：安装 Nginx、MariaDB、PHP ${PHP_DOT}"
apt-get update -qq
apt-get install -y -qq nginx mariadb-server mariadb-client \
  "php${PHP_DOT}-fpm" "php${PHP_DOT}-cli" "php${PHP_DOT}-mysql" "php${PHP_DOT}-mbstring" \
  "php${PHP_DOT}-xml" "php${PHP_DOT}-zip" "php${PHP_DOT}-gd" "php${PHP_DOT}-bcmath" \
  "php${PHP_DOT}-curl" ufw tar python3 mysql-client

php -r 'if (PHP_VERSION_ID < 80002) { fwrite(STDERR, "需要 PHP >= 8.0.2，当前 ".PHP_VERSION."\n"); exit(1); }'
echo "PHP $(php -r 'echo PHP_VERSION;')"

systemctl enable --now mariadb
ok=0
for _ in $(seq 1 60); do
  if mysqladmin ping --silent >/dev/null 2>&1 \
    || mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 1
done
[[ "$ok" == 1 ]] || die "MariaDB 60 秒内未就绪"

write_root_cnf() {
  umask 077
  cat > /root/.redi-mysql.cnf <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASS}
EOF
  chmod 600 /root/.redi-mysql.cnf
}

sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }

mysql_root() {
  if [[ -f /root/.redi-mysql.cnf ]] \
    && mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
    mysql --defaults-extra-file=/root/.redi-mysql.cnf "$@"
    return
  fi
  if mysql -u root -e "SELECT 1" </dev/null >/dev/null 2>&1; then
    mysql -u root "$@"
    return
  fi
  die "无法用 root 登录 MariaDB，请核对 MYSQL_ROOT_PASS"
}

if mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
  echo "MariaDB root 已配置"
elif mysql -u root -e "SELECT 1" </dev/null >/dev/null 2>&1; then
  root_sql=$(printf "ALTER USER 'root'@'localhost' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;" "$(sql_escape "$MYSQL_ROOT_PASS")")
  mysql -u root -e "$root_sql"
  write_root_cnf
else
  mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1" </dev/null >/dev/null 2>&1 && write_root_cnf || die "无法设置 root 密码"
fi
mysql_root -e "SELECT 1" >/dev/null

step "bind-address 与 skip-name-resolve"
cat > /etc/mysql/mariadb.conf.d/99-redi-bind.cnf <<'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF
bash "${SCRIPT_DIR}/apply-mysql-skip-name-resolve.sh"

step "创建库与本机账号"
db_sql=$(sql_escape "$DB_PASS")
sqlfile=$(mktemp)
trap 'rm -f "$sqlfile"' EXIT
{
  printf "CREATE DATABASE IF NOT EXISTS \`redi_admin\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
  printf "CREATE USER IF NOT EXISTS 'redi_admin'@'127.0.0.1' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "ALTER USER 'redi_admin'@'127.0.0.1' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON \`redi_admin\`.* TO 'redi_admin'@'127.0.0.1';\n"
  printf "CREATE USER IF NOT EXISTS 'redi_admin'@'localhost' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "ALTER USER 'redi_admin'@'localhost' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON \`redi_admin\`.* TO 'redi_admin'@'localhost';\n"
  printf "FLUSH PRIVILEGES;\n"
} > "$sqlfile"
mysql_root < "$sqlfile"

table_count="$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='redi_admin' AND table_name='admin_users'")"
if [[ "$table_count" == "0" ]]; then
  echo "导入 db/redi_admin.sql"
  mysql_root redi_admin < "${REPO_ROOT}/db/redi_admin.sql"
else
  echo "已有 admin_users，跳过全量导入"
fi

step "解压主控到 ${APP}"
if systemctl is-active --quiet redi-queue 2>/dev/null; then
  systemctl stop redi-queue || true
fi
umask 022
mkdir -p "$MASTER_INSTALL_ROOT"
env_bak=""
if [[ -f "${APP}/.env" ]]; then
  env_bak=$(mktemp)
  cp -a "${APP}/.env" "$env_bak"
fi
tar xzf "$TAR" -C "$MASTER_INSTALL_ROOT"
if [[ -n "$env_bak" ]]; then
  cp -a "$env_bak" "${APP}/.env"
  rm -f "$env_bak"
else
  cp "${APP}/.env.example" "${APP}/.env"
fi
redi_sync_master_env "${APP}/.env"

mkdir -p "${APP}/storage/framework/cache/data" "${APP}/storage/framework/sessions" \
  "${APP}/storage/framework/views" "${APP}/storage/logs" "${APP}/bootstrap/cache"
chown -R "${WEB_USER}:${WEB_USER}" "${APP}/storage" "${APP}/bootstrap/cache"

step "Laravel"
cd "$APP"
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
php artisan migrate --force
proc_count="$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='redi_admin' AND ROUTINE_NAME='redi_node_bump_skip'")"
[[ "${proc_count:-0}" != "0" ]] || die "缺少存储过程 redi_node_bump_skip"
mysql_root redi_admin -e "CALL redi_node_bump_skip('__deploy_smoke__')" >/dev/null \
  || die "redi_node_bump_skip 调用失败（常见原因：表 collation 与库不一致，请更新 db/redi_admin.sql）"
if [[ -n "$NODE_IPS" ]]; then
  redi_sync_node_ips_access "${SCRIPT_DIR}" "$NODE_IPS" "$DB_PASS" "$MYSQL_ROOT_PASS"
else
  echo "NODE_IPS 为空；加节点后写入 vars.env 并重跑本脚本"
  redi_sync_node_ips_access "${SCRIPT_DIR}" "" "$DB_PASS" "$MYSQL_ROOT_PASS" || true
fi
php artisan config:clear
[[ -L public/storage ]] || php artisan storage:link
chown -R "${WEB_USER}:${WEB_USER}" storage bootstrap/cache public/storage
chmod 640 .env
chown "${WEB_USER}:${WEB_USER}" .env

step "PHP-FPM (${PHP_DOT}) 与 Nginx"
pool="/etc/php/${PHP_DOT}/fpm/pool.d/www.conf"
[[ -f "$pool" ]] || die "找不到 $pool"
sed -i -E 's/^;?listen\.owner =.*/listen.owner = www-data/' "$pool"
sed -i -E 's/^;?listen\.group =.*/listen.group = www-data/' "$pool"
systemctl enable --now "php${PHP_DOT}-fpm"
systemctl restart "php${PHP_DOT}-fpm"

NGINX_SITE="/etc/nginx/sites-available/301-master.conf"
if [[ -f "$NGINX_SITE" ]] && ! grep -q '301-master-managed' "$NGINX_SITE"; then
  echo "保留已有 $NGINX_SITE（无 301-master-managed）"
else
  cat > "$NGINX_SITE" <<EOF
# 301-master-managed
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root ${APP}/public;
    index index.php;
    client_max_body_size 20m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ^~ /.well-known/ {
        allow all;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
    }

    location ~ /\\. {
        deny all;
    }
}
EOF
  ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/301-master.conf
  rm -f /etc/nginx/sites-enabled/default
fi
nginx -t
systemctl enable --now nginx
systemctl reload nginx

step "队列与 cron"
cat > /etc/systemd/system/redi-queue.service <<EOF
[Unit]
Description=301 Master queue worker
After=network.target mariadb.service

[Service]
Type=simple
User=${WEB_USER}
Group=${WEB_USER}
WorkingDirectory=${APP}
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now redi-queue

cat > /etc/cron.d/redi-renew <<EOF
SHELL=/bin/bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
10 3 * * * ${WEB_USER} cd ${APP} && /usr/bin/php artisan redi:renew-certificates >> ${APP}/storage/logs/renew.log 2>&1
10 4 * * * ${WEB_USER} cd ${APP} && /usr/bin/php artisan redi:prune-acme-challenges >> ${APP}/storage/logs/prune-acme.log 2>&1
EOF
chmod 644 /etc/cron.d/redi-renew

step "ufw：22、80、443；3306 仅 NODE_IPS"
ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

redi_warn_master_https_mismatch "${ADMIN_HTTPS}" "${MASTER_URL}"

echo
echo "主控已安装到 ${APP}"
echo "后台: ${MASTER_URL}/admin"
echo "初始账号见 DEPLOY.md。节点上执行: sudo bash scripts/ubuntu/deploy-node.sh"
