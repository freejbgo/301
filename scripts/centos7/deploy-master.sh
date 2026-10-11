#!/usr/bin/env bash
# CentOS 7.x 主控全自动部署。内核小版本不同不用重编：主控是 PHP，节点包是静态二进制。
#
# 在仓库里执行（不要把仓库克隆到 MASTER_INSTALL_ROOT，默认 /opt/301）：
#   cp scripts/centos7/vars.example.env scripts/centos7/vars.env
#   chmod 600 scripts/centos7/vars.env && vi scripts/centos7/vars.env
#   sudo bash scripts/centos7/deploy-master.sh
#
# 重复执行是安全的：已有库不会被 SQL 覆盖，已有 .env 和 APP_KEY 会保留。
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
VARS_FILE="${VARS_FILE:-${SCRIPT_DIR}/vars.env}"
TAR="${REDI_MASTER_TAR:-${REPO_ROOT}/master/master.tar.gz}"

die() { echo "错误: $*" >&2; exit 1; }
step() { echo ""; echo "==> $*"; }

if [[ "$(id -u)" -ne 0 ]]; then
  die "请用 root 执行: sudo bash $0"
fi
if [[ ! -f /etc/os-release ]]; then
  die "找不到 /etc/os-release"
fi
# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "centos" || "${VERSION_ID:-}" != 7* ]]; then
  die "这个脚本只用于 CentOS 7.x，当前是 ${ID:-unknown} ${VERSION_ID:-unknown}"
fi
if [[ ! -f "$VARS_FILE" ]]; then
  die "请先复制并编辑 ${SCRIPT_DIR}/vars.example.env 为 vars.env"
fi
# shellcheck disable=SC1090
source "$VARS_FILE"

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
is_ipv4() {
  local ip="$1" o
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    [[ "$o" =~ ^[0-9]+$ ]] || return 1
    ((10#$o <= 255)) || return 1
  done
}

export MASTER_IP DB_PASS MYSQL_ROOT_PASS MASTER_URL ADMIN_HTTPS
reject_text "DB_PASS" "$DB_PASS"
reject_text "MYSQL_ROOT_PASS" "$MYSQL_ROOT_PASS"
reject_text "MASTER_URL" "$MASTER_URL"
is_ipv4 "$MASTER_IP" || die "MASTER_IP 必须是 IPv4: $MASTER_IP"
[[ "$MASTER_URL" == http://* || "$MASTER_URL" == https://* ]] || die "MASTER_URL 要以 http:// 或 https:// 开头"
[[ "$ADMIN_HTTPS" == "true" || "$ADMIN_HTTPS" == "false" ]] || die "ADMIN_HTTPS 只能是 true 或 false"
if [[ "$ADMIN_HTTPS" == "true" && "$MASTER_URL" != https://* ]]; then
  die "ADMIN_HTTPS=true 时 MASTER_URL 要用 https://"
fi
for ip in $NODE_IPS; do
  is_ipv4 "$ip" || die "NODE_IPS 里有非法 IPv4: $ip"
done
[[ -f "$TAR" ]] || die "找不到主控发布包: $TAR"
[[ -f "${REPO_ROOT}/db/redi_admin.sql" ]] || die "找不到 db/redi_admin.sql"

APP="${MASTER_INSTALL_ROOT}/master"
if [[ -d "${APP}/master" && -f "${APP}/master/artisan" ]]; then
  die "${APP}/master 已有一份克隆出来的源码。请把仓库放在其他目录，发布包会解压到 ${APP}"
fi

step "CentOS $(uname -r)。不按内核编译，安装 PHP 8.0 和 MariaDB 10.6"
use_vault() {
  local f
  shopt -s nullglob
  for f in /etc/yum.repos.d/CentOS-*.repo; do
    sed -i \
      -e 's/^mirrorlist=/#mirrorlist=/' \
      -e 's/^# *baseurl=/baseurl=/' \
      -e 's|http://mirror.centos.org/centos|https://vault.centos.org/centos|g' \
      -e 's|https://mirror.centos.org/centos|https://vault.centos.org/centos|g' \
      "$f"
  done
  shopt -u nullglob
}
use_vault
yum install -y epel-release yum-utils
if compgen -G "/etc/yum.repos.d/epel*.repo" > /dev/null; then
  sed -i \
    -e 's/^metalink=/#metalink=/' \
    -e 's/^mirrorlist=/#mirrorlist=/' \
    -e 's|^# *baseurl=.*epel/|baseurl=https://archives.fedoraproject.org/pub/archive/epel/|' \
    /etc/yum.repos.d/epel*.repo
fi
yum install -y "https://rpms.remirepo.net/enterprise/remi-release-7.rpm"
yum-config-manager --enable remi-php80
yum install -y nginx firewalld tar policycoreutils-python \
  php php-cli php-fpm php-mysqlnd php-mbstring php-xml php-json php-zip \
  php-gd php-bcmath php-opcache php-process

rpm --import https://archive.mariadb.org/PublicKey
cat > /etc/yum.repos.d/mariadb.repo <<'EOF'
[mariadb]
name = MariaDB 10.6
baseurl = https://archive.mariadb.org/yum/10.6/centos7-amd64
gpgkey = https://archive.mariadb.org/PublicKey
gpgcheck = 1
enabled = 1
EOF
yum install -y MariaDB-server MariaDB-client MariaDB-common MariaDB-compat

php -r 'if (PHP_VERSION_ID < 70300 || PHP_VERSION_ID >= 80100) { fwrite(STDERR, "需要 PHP 7.3 或 8.0，当前 ".PHP_VERSION."\n"); exit(1); }'
echo "PHP $(php -r 'echo PHP_VERSION;')"

step "启动 MariaDB、Nginx、PHP-FPM、firewalld"
systemctl enable --now mariadb
systemctl enable --now firewalld
ok=0
for _ in $(seq 1 60); do
  if mysqladmin --protocol=socket -u root ping --silent >/dev/null 2>&1 \
    || mysqladmin --defaults-extra-file=/root/.redi-mysql.cnf ping --silent >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 1
done
[[ "$ok" == 1 ]] || die "MariaDB 60 秒内没有就绪"

write_root_cnf() {
  umask 077
  cat > /root/.redi-mysql.cnf <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASS}
EOF
  chmod 600 /root/.redi-mysql.cnf
}

mysql_root() {
  if [[ -f /root/.redi-mysql.cnf ]] \
    && mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
    mysql --defaults-extra-file=/root/.redi-mysql.cnf "$@"
    return
  fi
  if mysql --protocol=socket -u root -e "SELECT 1" </dev/null >/dev/null 2>&1; then
    mysql --protocol=socket -u root "$@"
    return
  fi
  die "无法用 root 登录 MariaDB。请核对 MYSQL_ROOT_PASS"
}

sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }

if mysql --defaults-extra-file=/root/.redi-mysql.cnf -e "SELECT 1" </dev/null >/dev/null 2>&1; then
  echo "MariaDB root 密码已配置"
elif mysql --protocol=socket -u root -e "SELECT 1" </dev/null >/dev/null 2>&1; then
  root_sql=$(printf "ALTER USER 'root'@'localhost' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;" "$(sql_escape "$MYSQL_ROOT_PASS")")
  if ! mysql --protocol=socket -u root -e "$root_sql"; then
    root_sql=$(printf "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('%s'); FLUSH PRIVILEGES;" "$(sql_escape "$MYSQL_ROOT_PASS")")
    mysql --protocol=socket -u root -e "$root_sql"
  fi
  write_root_cnf
elif mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1" </dev/null >/dev/null 2>&1; then
  write_root_cnf
else
  die "无法用 root 登录 MariaDB。请核对 MYSQL_ROOT_PASS"
fi
mysql_root -e "SELECT 1" >/dev/null

step "监听所有 IPv4，并关闭反向域名解析"
cat > /etc/my.cnf.d/redi-bind-address.cnf <<'EOF'
# 节点从公网 IP 连接。3306 只由 firewalld 对 NODE_IPS 放行，不要对全网打开。
[mysqld]
bind-address=0.0.0.0
EOF
bash "${SCRIPT_DIR}/apply-mysql-skip-name-resolve.sh"

step "创建库和本机账号"
db_sql=$(sql_escape "$DB_PASS")
sqlfile=$(mktemp)
trap 'rm -f "$sqlfile"' EXIT
{
  printf "CREATE DATABASE IF NOT EXISTS \`redi_admin\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
  printf "CREATE USER IF NOT EXISTS 'redi_admin'@'127.0.0.1' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "ALTER USER 'redi_admin'@'127.0.0.1' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "GRANT SELECT, INSERT, UPDATE, DELETE ON \`redi_admin\`.* TO 'redi_admin'@'127.0.0.1';\n"
  printf "CREATE USER IF NOT EXISTS 'redi_admin'@'localhost' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "ALTER USER 'redi_admin'@'localhost' IDENTIFIED BY '%s';\n" "$db_sql"
  printf "GRANT SELECT, INSERT, UPDATE, DELETE ON \`redi_admin\`.* TO 'redi_admin'@'localhost';\n"
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

if [[ -n "$NODE_IPS" ]]; then
  export MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASS"
  for ip in $NODE_IPS; do
    bash "${SCRIPT_DIR}/grant-node-mysql.sh" "$ip" "$DB_PASS"
    bash "${SCRIPT_DIR}/firewalld-allow-mysql-from-node.sh" "$ip"
  done
else
  echo "NODE_IPS 为空，尚未授权节点。加节点前写上 IP 并重新执行本脚本。"
fi

step "解压主控发布包到 ${APP}"
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
  echo "保留已有 .env"
else
  cp "${APP}/.env.example" "${APP}/.env"
  php -r '
    $p = $argv[1];
    $env = file_get_contents($p);
    $map = ["APP_URL" => getenv("MASTER_URL"), "DB_PASSWORD" => getenv("DB_PASS"), "ADMIN_HTTPS" => getenv("ADMIN_HTTPS")];
    foreach ($map as $k => $v) {
      $env = preg_replace("/^".preg_quote($k, "/")."=.*/m", $k."=".$v, $env, 1, $n);
      if ($n !== 1) { fwrite(STDERR, "未写上 {$k}\n"); exit(1); }
    }
    file_put_contents($p, $env);
  ' "${APP}/.env"
  echo "已写入 .env"
fi

mkdir -p \
  "${APP}/storage/app/public" \
  "${APP}/storage/framework/cache/data" \
  "${APP}/storage/framework/sessions" \
  "${APP}/storage/framework/views" \
  "${APP}/storage/logs" \
  "${APP}/bootstrap/cache"
chown -R nginx:nginx "${APP}/storage" "${APP}/bootstrap/cache"
chmod -R ug+rwx "${APP}/storage" "${APP}/bootstrap/cache"

step "Laravel 密钥、迁移、存储链接"
cd "$APP"
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi
php artisan migrate --force
if [[ ! -L public/storage ]]; then
  php artisan storage:link
fi
chown -R nginx:nginx storage bootstrap/cache public/storage
chmod 640 .env
chown nginx:nginx .env

step "PHP-FPM 与 Nginx"
www_conf="/etc/php-fpm.d/www.conf"
[[ -f "$www_conf" ]] || die "找不到 $www_conf"
set_ini() {
  local key="$1" val="$2" file="$3"
  local key_re="${key//./\\.}"
  if grep -qE "^[;[:space:]]*${key_re}[[:space:]]*=" "$file"; then
    sed -i -E "s|^[;[:space:]]*${key_re}[[:space:]]*=.*|${key} = ${val}|" "$file"
  else
    printf '\n%s = %s\n' "$key" "$val" >> "$file"
  fi
}
set_ini user nginx "$www_conf"
set_ini group nginx "$www_conf"
set_ini listen /var/run/php-fpm/www.sock "$www_conf"
set_ini listen.owner nginx "$www_conf"
set_ini listen.group nginx "$www_conf"
set_ini listen.mode 0660 "$www_conf"
mkdir -p /var/run/php-fpm
systemctl enable --now php-fpm
systemctl restart php-fpm

if ! grep -q '301-master-managed' /etc/nginx/nginx.conf 2>/dev/null; then
  if [[ ! -f /etc/nginx/nginx.conf.orig.redi ]]; then
    cp -a /etc/nginx/nginx.conf /etc/nginx/nginx.conf.orig.redi
  fi
  cat > /etc/nginx/nginx.conf <<'EOF'
# 301-master-managed
user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log;
pid /var/run/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;
    sendfile      on;
    keepalive_timeout 65;
    client_max_body_size 20m;
    include /etc/nginx/conf.d/*.conf;
}
EOF
fi
if [[ ! -f /etc/nginx/conf.d/301-master.conf ]]; then
cat > /etc/nginx/conf.d/301-master.conf <<EOF
server {
    listen 80 default_server;
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
        try_files \$uri =404;
        fastcgi_pass unix:/var/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\\. {
        deny all;
    }
}
EOF
else
  echo "已有 /etc/nginx/conf.d/301-master.conf，保持不变"
fi
nginx -t
systemctl enable --now nginx
systemctl reload nginx

if [[ "$(getenforce 2>/dev/null || echo Disabled)" != "Disabled" ]]; then
  setsebool -P httpd_can_network_connect 1 || true
  setsebool -P httpd_can_network_connect_db 1 || true
  if command -v semanage >/dev/null 2>&1; then
    semanage fcontext -a -t httpd_sys_content_t "${APP}(/.*)?" \
      || semanage fcontext -m -t httpd_sys_content_t "${APP}(/.*)?"
    semanage fcontext -a -t httpd_sys_rw_content_t "${APP}/storage(/.*)?" \
      || semanage fcontext -m -t httpd_sys_rw_content_t "${APP}/storage(/.*)?"
    semanage fcontext -a -t httpd_sys_rw_content_t "${APP}/bootstrap/cache(/.*)?" \
      || semanage fcontext -m -t httpd_sys_rw_content_t "${APP}/bootstrap/cache(/.*)?"
    restorecon -RF "$APP" || true
  else
    chcon -R -t httpd_sys_content_t "$APP" || true
    chcon -R -t httpd_sys_rw_content_t "${APP}/storage" "${APP}/bootstrap/cache" || true
  fi
fi

step "队列和证书续期"
cat > /etc/systemd/system/redi-queue.service <<EOF
[Unit]
Description=301 Master queue worker (certificates)
After=network.target mariadb.service

[Service]
Type=simple
User=nginx
WorkingDirectory=${APP}
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --timeout=320 --tries=100 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now redi-queue
systemctl restart redi-queue

cat > /etc/cron.d/redi-renew <<EOF
SHELL=/bin/bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
10 3 * * * nginx cd ${APP} && /usr/bin/php artisan redi:renew-certificates >> ${APP}/storage/logs/renew.log 2>&1
EOF
chmod 644 /etc/cron.d/redi-renew

step "防火墙：22、80、443。3306 只放行节点 IP"
add_svc() {
  firewall-cmd --permanent --query-service="$1" >/dev/null \
    || firewall-cmd --permanent --add-service="$1"
}
add_svc ssh
add_svc http
add_svc https
firewall-cmd --reload

echo
echo "主控已安装到 ${APP}"
echo "后台: ${MASTER_URL}/admin"
echo "初始账号 admin / Redi301@Install2026 ，登录后马上修改。"
echo "建好节点并复制 Token 后，再到节点机器执行 deploy-node.sh。"
