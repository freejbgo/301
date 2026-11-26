#!/bin/bash
# CentOS 7 主控一条命令。不用填 IP，安装包已经在仓库 pkg/ 里。
#
#   curl -fsSL https://github.com/freejbgo/301/raw/main/master.sh -o master.sh && chmod +x master.sh && bash master.sh
#
# 只有自动识别不到公网 IP 时，才需要再执行一次：MASTER_IP=你的公网IP bash master.sh
set -euo pipefail

REPO_URL="https://github.com/freejbgo/301.git"
SRC="/root/301"
REQ_MASTER_IP="${MASTER_IP:-}"

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
  local a b
  case "$1" in
    10.*|127.*|0.*|169.254.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 0 ;;
  esac
  # 100.64.0.0/10 是运营商级 NAT。有的云主机把它配在网卡上，不能当成公网入口。
  IFS=. read -r a b _ _ <<< "$1"
  if [[ "$a" == "100" && "${b:-}" =~ ^[0-9]+$ ]]; then
    ((10#$b >= 64 && 10#$b <= 127)) && return 0
  fi
  return 1
}

# 从一段回包里取第一个公网 IPv4。内网地址直接跳过。
first_public_ip() {
  local blob="$1" ip
  while [[ "$blob" =~ ([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}) ]]; do
    ip="${BASH_REMATCH[1]}"
    if valid_ip "$ip" && ! is_private "$ip"; then
      printf '%s\n' "$ip"
      return 0
    fi
    blob="${blob#*"${ip}"}"
  done
  return 1
}

curl_text() {
  local url="$1" max="${2:-4}"
  curl -4 -fsS --connect-timeout 2 --max-time "$max" "$url" 2>/dev/null || true
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

detect_local_public_ip() {
  local ip
  command -v ip >/dev/null 2>&1 || return 1
  while read -r ip; do
    ip="${ip%%/*}"
    if valid_ip "$ip" && ! is_private "$ip"; then
      printf '%s\n' "$ip"
      return 0
    fi
  done < <(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}')
  return 1
}

# 网卡上没有公网地址时，云控制台分配的公网 IP 写在元数据里。
detect_metadata_public_ip() {
  local url body ip token
  local urls=(
    "http://100.100.100.200/latest/meta-data/eipv4"
    "http://100.100.100.200/latest/meta-data/public-ipv4"
    "http://metadata.tencentyun.com/latest/meta-data/public-ipv4"
    "http://169.254.169.254/latest/meta-data/public-ipv4"
  )
  for url in "${urls[@]}"; do
    body="$(curl_text "$url" 2)"
    if ip="$(first_public_ip "$body")"; then
      printf '%s\n' "$ip"
      return 0
    fi
  done
  token="$(curl -4 -fsS --connect-timeout 1 --max-time 2 -X PUT \
    -H "X-aws-ec2-metadata-token-ttl-seconds: 60" \
    "http://169.254.169.254/latest/api/token" 2>/dev/null || true)"
  if [[ -n "$token" ]]; then
    body="$(curl -4 -fsS --connect-timeout 1 --max-time 2 \
      -H "X-aws-ec2-metadata-token: ${token}" \
      "http://169.254.169.254/latest/meta-data/public-ipv4" 2>/dev/null || true)"
    if ip="$(first_public_ip "$body")"; then
      printf '%s\n' "$ip"
      return 0
    fi
  fi
  return 1
}

# 访问公网，对方看到的来源地址就是这台机器的外部 IP。回包里夹着说明文字也可以。
detect_egress_public_ip() {
  local url body ip
  local urls=(
    "https://4.ipw.cn"
    "http://members.3322.org/dyndns/getip"
    "https://myip.ipip.net"
    "https://ip.sb"
    "https://ifconfig.me/ip"
    "https://api.ipify.org"
    "https://ipv4.icanhazip.com"
  )
  for url in "${urls[@]}"; do
    body="$(curl_text "$url" 5)"
    if ip="$(first_public_ip "$body")"; then
      printf '%s\n' "$ip"
      return 0
    fi
  done
  return 1
}

# 结果写到 DETECTED_IP、DETECTED_VIA。网卡上的内网地址不会拿来当主控地址。
detect_public_ip() {
  local ip
  DETECTED_IP=""
  DETECTED_VIA=""
  if ip="$(detect_local_public_ip)"; then
    DETECTED_IP="$ip"
    DETECTED_VIA="本机网卡上的公网地址"
    return 0
  fi
  echo "==> 网卡上只有内网地址，改为查询云平台公网 IP 和公网出口地址。"
  if ip="$(detect_metadata_public_ip)"; then
    DETECTED_IP="$ip"
    DETECTED_VIA="云平台记录的公网地址（网卡上只有内网 IP）"
    return 0
  fi
  if ip="$(detect_egress_public_ip)"; then
    DETECTED_IP="$ip"
    DETECTED_VIA="从公网看到的出口地址（网卡上只有内网 IP）"
    return 0
  fi
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

avail_kb="$(df -Pk / | awk 'NR==2 {print $4}')"
[[ "${avail_kb:-0}" -ge 5000000 ]] || die "磁盘剩余不足 5GB。请把系统盘扩到至少 20GB 后再装"

echo "==> CentOS 7 主控安装开始。安装包直接用仓库里打好的，不用在这台机器上编译。"
use_centos7_vault
yum clean all
yum makecache
yum install -y git curl ca-certificates openssl iproute

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

old_ips=""
if [[ -f "${SRC}/scripts/centos7/vars.env" ]]; then
  old_ips="$(sed -n 's/^NODE_IPS="\([^"]*\)".*/\1/p' "${SRC}/scripts/centos7/vars.env")"
fi

echo "==> 下载项目到 ${SRC}"
export GIT_TERMINAL_PROMPT=0
if [[ ! -s "${SRC}/pkg/master.tar.gz" || ! -s "${SRC}/pkg/redi-multi.tar.gz" ]]; then
  rm -rf "$SRC"
  if ! git clone --depth 1 "$REPO_URL" "$SRC"; then
    rm -rf "$SRC"
    die "下载代码失败。请先把 ${REPO_URL} 设为公开，并确认这台机器能访问 GitHub"
  fi
fi
[[ -s "${SRC}/pkg/master.tar.gz" && -s "${SRC}/pkg/redi-multi.tar.gz" ]] || die "仓库里没有安装包 pkg/master.tar.gz 或 pkg/redi-multi.tar.gz"
cp -f "${SRC}/pkg/master.tar.gz" /tmp/master.tar.gz
cp -f "${SRC}/pkg/redi-multi.tar.gz" /tmp/redi-multi.tar.gz

SAVED_DB=""
SAVED_ROOT=""
SAVED_MASTER=""
SAVED_REDIRECT=""
if [[ -f /root/301-passwords.txt ]]; then
  # shellcheck disable=SC1091
  source /root/301-passwords.txt
  SAVED_DB="${DB_PASS:-}"
  SAVED_ROOT="${MYSQL_ROOT_PASS:-}"
  SAVED_MASTER="${MASTER_IP:-}"
  SAVED_REDIRECT="${DEFAULT_REDIRECT_URL:-}"
fi

DETECTED_IP=""
DETECTED_VIA=""
if [[ -n "$REQ_MASTER_IP" ]]; then
  MASTER_IP="$REQ_MASTER_IP"
  DETECTED_VIA="命令里指定的地址"
elif [[ -n "$SAVED_MASTER" ]] && valid_ip "$SAVED_MASTER" && ! is_private "$SAVED_MASTER"; then
  MASTER_IP="$SAVED_MASTER"
  DETECTED_VIA="上次安装保存的地址"
else
  detect_public_ip || true
  MASTER_IP="${DETECTED_IP:-}"
fi
valid_ip "${MASTER_IP:-}" || die "没能自动识别公网 IP。请执行：MASTER_IP=你的公网IP bash master.sh"
is_private "$MASTER_IP" && die "识别到的是内网 IP ${MASTER_IP}。请执行：MASTER_IP=你的公网IP bash master.sh"
echo "==> 主控公网 IP: ${MASTER_IP}（${DETECTED_VIA}）"

DB_PASS="$SAVED_DB"
MYSQL_ROOT_PASS="$SAVED_ROOT"
if [[ -z "$DB_PASS" ]]; then
  DB_PASS="$(openssl rand -hex 16)"
fi
if [[ -z "$MYSQL_ROOT_PASS" ]]; then
  MYSQL_ROOT_PASS="$(openssl rand -hex 16)"
fi
DEFAULT_REDIRECT_URL="${SAVED_REDIRECT:-https://www.example.com}"

umask 077
cat > /root/301-passwords.txt <<EOF
MASTER_IP='${MASTER_IP}'
DB_PASS='${DB_PASS}'
MYSQL_ROOT_PASS='${MYSQL_ROOT_PASS}'
DEFAULT_REDIRECT_URL='${DEFAULT_REDIRECT_URL}'
EOF
cat > /root/301-for-node.txt <<EOF
MASTER_IP='${MASTER_IP}'
DB_PASS='${DB_PASS}'
DEFAULT_REDIRECT_URL='${DEFAULT_REDIRECT_URL}'
EOF
chmod 600 /root/301-passwords.txt /root/301-for-node.txt
umask 022

NODE_IPS=""
for ip in ${old_ips:-}; do
  [[ -z "$ip" ]] && continue
  valid_ip "$ip" || continue
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
NODE_PUBLIC_IP=""
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
echo "节点不用现在填 IP。登录后台新建节点后，再在节点那台机器上安装。"
