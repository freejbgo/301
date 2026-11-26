#!/bin/bash
# CentOS 7 主控一条命令安装。仓库需可公开克隆。
#
#   curl -fsSL https://github.com/freejbgo/301/raw/main/master.sh -o master.sh && chmod +x master.sh && bash master.sh
#
# 可选环境变量：
#   MASTER_IP=1.2.3.4     不填则自动探测公网 IPv4
#   NODE_IP=5.6.7.8       第一台节点公网 IP，装的时候就放行 3306
#   REDI_REBUILD=1        已有安装包时也重新打包
#   REDI_COMPOSER_MIRROR=0  不用阿里云 Composer 镜像
set -euo pipefail

REPO_URL="https://github.com/freejbgo/301.git"
SRC="/root/301"
REQ_MASTER_IP="${MASTER_IP:-}"
REQ_NODE_IP="${NODE_IP:-}"
if [[ -n "${DEFAULT_REDIRECT_URL+x}" ]]; then
  REQ_REDIRECT_SET=1
  REQ_REDIRECT="$DEFAULT_REDIRECT_URL"
else
  REQ_REDIRECT_SET=0
  REQ_REDIRECT=""
fi

die() { echo "错误: $*" >&2; exit 1; }

valid_ip() {
  local ip="$1" o a b c d
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r a b c d <<< "$ip"
  for o in "$a" "$b" "$c" "$d"; do
    [[ "$o" =~ ^[0-9]+$ ]] || return 1
    ((10#$o <= 255)) || return 1
  done
}

is_private() {
  case "$1" in
    10.*|127.*|0.*|169.254.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 0 ;;
    *) return 1 ;;
  esac
}

use_centos7_vault() {
  if [[ -f /etc/yum/pluginconf.d/fastestmirror.conf ]]; then
    sed -i 's/^enabled=1/enabled=0/' /etc/yum/pluginconf.d/fastestmirror.conf
  fi
  if grep -q '^ip_resolve=' /etc/yum.conf 2>/dev/null; then
    sed -i 's/^ip_resolve=.*/ip_resolve=4/' /etc/yum.conf
  else
    echo 'ip_resolve=4' >> /etc/yum.conf
  fi
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
  if ! grep -Rqs 'vault.centos.org' /etc/yum.repos.d/CentOS-*.repo 2>/dev/null; then
    cat > /etc/yum.repos.d/CentOS-Base.repo <<'EOF'
[base]
name=CentOS-7 - Base
baseurl=https://vault.centos.org/centos/7/os/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
enabled=1

[updates]
name=CentOS-7 - Updates
baseurl=https://vault.centos.org/centos/7/updates/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
enabled=1

[extras]
name=CentOS-7 - Extras
baseurl=https://vault.centos.org/centos/7/extras/$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
enabled=1
EOF
  fi
  mkdir -p /etc/yum.repos.d/disabled-by-redi
  shopt -s nullglob
  for f in /etc/yum.repos.d/CentOS-*.repo; do
    if ! grep -q 'vault.centos.org' "$f"; then
      mv "$f" /etc/yum.repos.d/disabled-by-redi/
    fi
  done
  shopt -u nullglob
}

fix_epel() {
  if compgen -G "/etc/yum.repos.d/epel*.repo" > /dev/null; then
    sed -i \
      -e 's/^metalink=/#metalink=/' \
      -e 's/^mirrorlist=/#mirrorlist=/' \
      -e 's|^# *baseurl=.*epel/|baseurl=https://archives.fedoraproject.org/pub/archive/epel/|' \
      /etc/yum.repos.d/epel*.repo
  fi
}

detect_public_ip() {
  local url ip
  for url in "https://ifconfig.me/ip" "https://api.ipify.org" "https://ipv4.icanhazip.com"; do
    ip="$(curl -4 -fsS --max-time 8 "$url" 2>/dev/null | tr -d '[:space:]' || true)"
    if valid_ip "$ip" && ! is_private "$ip"; then
      printf '%s\n' "$ip"
      return 0
    fi
  done
  return 1
}

[[ "$(id -u)" -eq 0 ]] || die "请用 root 执行"
[[ -f /etc/os-release ]] || die "找不到 /etc/os-release"
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "centos" && "${VERSION_ID:-}" == 7* ]] || die "这个脚本只用于 CentOS 7.x，当前是 ${ID:-unknown} ${VERSION_ID:-unknown}"

if [[ -n "$REQ_MASTER_IP" ]]; then
  valid_ip "$REQ_MASTER_IP" || die "MASTER_IP 不是 IPv4: $REQ_MASTER_IP"
  is_private "$REQ_MASTER_IP" && die "MASTER_IP 是内网地址，请填云控制台里的公网 IP"
fi
if [[ -n "$REQ_NODE_IP" ]]; then
  valid_ip "$REQ_NODE_IP" || die "NODE_IP 不是 IPv4: $REQ_NODE_IP"
  is_private "$REQ_NODE_IP" && die "NODE_IP 是内网地址，请填节点公网 IP"
  [[ -z "$REQ_MASTER_IP" || "$REQ_MASTER_IP" != "$REQ_NODE_IP" ]] || die "主控和节点要两台机器，两个 IP 不能相同"
fi
if [[ "$REQ_REDIRECT_SET" == "1" ]]; then
  [[ "$REQ_REDIRECT" == http://* || "$REQ_REDIRECT" == https://* ]] || die "DEFAULT_REDIRECT_URL 要以 http:// 或 https:// 开头"
  [[ ! "$REQ_REDIRECT" =~ [[:space:]\'\"\`\#\$\\] ]] || die "跳转网址不要包含空格、引号、#、$ 或反斜杠"
fi

avail_kb="$(df -Pk / | awk 'NR==2 {print $4}')"
[[ "${avail_kb:-0}" -ge 5000000 ]] || die "磁盘剩余不足 5GB。请把系统盘扩到至少 20GB 后再装"

echo "==> CentOS 7 主控安装开始。打包和装软件一般要 10 到 30 分钟。"
use_centos7_vault
yum clean all
yum makecache
yum install -y git curl ca-certificates openssl

mem_kb="$(awk '/MemTotal/{print $2}' /proc/meminfo)"
if [[ "${mem_kb:-0}" -lt 1800000 ]]; then
  echo "==> 内存小于 2GB，添加 2GB 交换空间"
  if [[ ! -f /swapfile ]]; then
    dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
  fi
  swapon -s | grep -q /swapfile || swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> 下载项目到 ${SRC}"
export GIT_TERMINAL_PROMPT=0
if [[ ! -f "${SRC}/scripts/centos7/deploy-master.sh" ]]; then
  rm -rf "$SRC"
  if ! git clone --depth 1 "$REPO_URL" "$SRC"; then
    rm -rf "$SRC"
    die "下载代码失败。请先把 ${REPO_URL} 设为公开，并确认这台机器能访问 GitHub"
  fi
fi

if [[ -s /tmp/master.tar.gz && -s /tmp/redi-multi.tar.gz && "${REDI_REBUILD:-0}" != "1" ]]; then
  echo "==> 已有 /tmp/master.tar.gz 和 /tmp/redi-multi.tar.gz，跳过打包"
else
  echo "==> 安装 PHP 8.0、Go、Composer"
  yum install -y epel-release yum-utils
  fix_epel
  if ! rpm -q remi-release >/dev/null 2>&1; then
    yum install -y "https://rpms.remirepo.net/enterprise/remi-release-7.rpm"
  fi
  fix_epel
  yum-config-manager --enable remi-php80
  yum install -y tar unzip openssl file \
    php php-cli php-fpm php-mysqlnd php-mbstring php-xml php-pecl-zip \
    php-gd php-bcmath php-opcache php-process
  php -r 'if (PHP_VERSION_ID < 80000 || PHP_VERSION_ID >= 80100) { fwrite(STDERR, "需要 PHP 8.0，当前 ".PHP_VERSION."\n"); exit(1); }'

  export PATH="/usr/local/go/bin:/usr/local/bin:${PATH}"
  if ! go version 2>/dev/null | grep -q 'go1.21.13 '; then
    curl -fL --retry 3 -o /tmp/go1.21.13.linux-amd64.tar.gz https://go.dev/dl/go1.21.13.linux-amd64.tar.gz
    rm -rf /usr/local/go
    tar -C /usr/local -xzf /tmp/go1.21.13.linux-amd64.tar.gz
  fi
  go version

  if [[ ! -x /usr/local/bin/composer ]]; then
    curl -fL --retry 3 -o /usr/local/bin/composer https://getcomposer.org/download/2.2.25/composer.phar
    chmod +x /usr/local/bin/composer
  fi
  export COMPOSER_ALLOW_SUPERUSER=1
  export GOPROXY="${GOPROXY:-https://goproxy.cn,https://proxy.golang.org,direct}"
  if [[ "${REDI_COMPOSER_MIRROR:-1}" == "1" ]]; then
    composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/ || true
  fi

  echo "==> 打包主控和节点"
  cd "${SRC}/master/master"
  if [[ ! -f .env ]]; then
    cp .env.example .env
  fi
  if ! composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader; then
    composer config -g --unset repos.packagist || true
    composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
  fi
  cd "$SRC"
  bash scripts/build-release-tarballs.sh
  cp -f "${SRC}/master/master.tar.gz" /tmp/master.tar.gz
  cp -f "${SRC}/node/redi-multi.tar.gz" /tmp/redi-multi.tar.gz
fi
[[ -s /tmp/master.tar.gz && -s /tmp/redi-multi.tar.gz ]] || die "未找到主控发布包"

SAVED_DB=""
SAVED_ROOT=""
SAVED_MASTER=""
SAVED_NODE=""
SAVED_REDIRECT=""
if [[ -f /root/301-passwords.txt ]]; then
  # shellcheck disable=SC1091
  source /root/301-passwords.txt
  SAVED_DB="${DB_PASS:-}"
  SAVED_ROOT="${MYSQL_ROOT_PASS:-}"
  SAVED_MASTER="${MASTER_IP:-}"
  SAVED_NODE="${NODE_IP:-}"
  SAVED_REDIRECT="${DEFAULT_REDIRECT_URL:-}"
fi

if [[ -n "$REQ_MASTER_IP" ]]; then
  MASTER_IP="$REQ_MASTER_IP"
elif [[ -n "$SAVED_MASTER" ]] && valid_ip "$SAVED_MASTER" && ! is_private "$SAVED_MASTER"; then
  MASTER_IP="$SAVED_MASTER"
else
  MASTER_IP="$(detect_public_ip || true)"
fi
valid_ip "${MASTER_IP:-}" || die "没能自动识别公网 IP。请执行：MASTER_IP=你的公网IP bash master.sh"
is_private "$MASTER_IP" && die "识别到的是内网 IP ${MASTER_IP}。请执行：MASTER_IP=你的公网IP bash master.sh"

if [[ -n "$REQ_NODE_IP" ]]; then
  NODE_IP="$REQ_NODE_IP"
elif [[ -n "$SAVED_NODE" ]] && valid_ip "$SAVED_NODE"; then
  NODE_IP="$SAVED_NODE"
else
  NODE_IP=""
fi
[[ -z "$NODE_IP" || "$NODE_IP" != "$MASTER_IP" ]] || die "主控和节点要两台机器，两个 IP 不能相同"

DB_PASS="$SAVED_DB"
MYSQL_ROOT_PASS="$SAVED_ROOT"
if [[ -z "$DB_PASS" ]]; then
  DB_PASS="$(openssl rand -hex 16)"
fi
if [[ -z "$MYSQL_ROOT_PASS" ]]; then
  MYSQL_ROOT_PASS="$(openssl rand -hex 16)"
fi
if [[ "$REQ_REDIRECT_SET" == "1" ]]; then
  DEFAULT_REDIRECT_URL="$REQ_REDIRECT"
elif [[ -n "$SAVED_REDIRECT" ]]; then
  DEFAULT_REDIRECT_URL="$SAVED_REDIRECT"
else
  DEFAULT_REDIRECT_URL="https://www.example.com"
fi
[[ "$DEFAULT_REDIRECT_URL" == http://* || "$DEFAULT_REDIRECT_URL" == https://* ]] || die "DEFAULT_REDIRECT_URL 要以 http:// 或 https:// 开头"
[[ ! "$DEFAULT_REDIRECT_URL" =~ [[:space:]\'\"\`\#\$\\] ]] || die "跳转网址不要包含空格、引号、#、$ 或反斜杠"

umask 077
cat > /root/301-passwords.txt <<EOF
MASTER_IP='${MASTER_IP}'
NODE_IP='${NODE_IP}'
DB_PASS='${DB_PASS}'
MYSQL_ROOT_PASS='${MYSQL_ROOT_PASS}'
DEFAULT_REDIRECT_URL='${DEFAULT_REDIRECT_URL}'
EOF
cat > /root/301-for-node.txt <<EOF
MASTER_IP='${MASTER_IP}'
NODE_IP='${NODE_IP}'
DB_PASS='${DB_PASS}'
DEFAULT_REDIRECT_URL='${DEFAULT_REDIRECT_URL}'
EOF
chmod 600 /root/301-passwords.txt /root/301-for-node.txt
umask 022

old_ips=""
if [[ -f "${SRC}/scripts/centos7/vars.env" ]]; then
  old_ips="$(sed -n 's/^NODE_IPS="\([^"]*\)".*/\1/p' "${SRC}/scripts/centos7/vars.env")"
fi
NODE_IPS=""
if [[ -n "$NODE_IP" ]]; then
  NODE_IPS="$NODE_IP"
fi
for ip in ${old_ips:-}; do
  [[ -z "$ip" || "$ip" == "$NODE_IP" ]] && continue
  case " ${NODE_IPS} " in
    *" ${ip} "*) ;;
    *) NODE_IPS="${NODE_IPS:+$NODE_IPS }${ip}" ;;
  esac
done

cat > "${SRC}/scripts/centos7/vars.env" <<EOF
MASTER_IP="${MASTER_IP}"
DB_PASS="${DB_PASS}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS}"
NODE_IPS="${NODE_IPS}"
REDI_MASTER_TAR="/tmp/master.tar.gz"
REDI_NODE_TAR="/tmp/redi-multi.tar.gz"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
MASTER_URL="http://${MASTER_IP}"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="${NODE_IP}"
DEFAULT_REDIRECT_URL="${DEFAULT_REDIRECT_URL}"
REDI_MASTER_URL="http://${MASTER_IP}"
REDI_MASTER_INSECURE="1"
REDI_DB_TLS=""
REDI_MIN_AGENT_VERSION="0.1.3"
REDI_ALLOW_TRUST_ALL_PROXIES="false"
REDI_NODE_TOKEN=""
EOF
chmod 600 "${SRC}/scripts/centos7/vars.env"

cd "$SRC"
bash scripts/centos7/deploy-master.sh

echo
echo "======== 主控安装完成 ========"
systemctl is-active nginx php-fpm mariadb redi-queue
echo "后台地址: http://${MASTER_IP}/admin"
echo "用户名: admin"
echo "初始密码: Redi301@Install2026"
echo "登录后按页面要求改掉这个密码。"
echo "数据库密码在 /root/301-passwords.txt"
if [[ -z "$NODE_IP" ]]; then
  echo "还没有放行节点。后台新建节点后，在这台机器再执行："
  echo "  NODE_IP=节点公网IP bash master.sh"
fi
