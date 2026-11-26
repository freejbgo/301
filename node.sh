#!/bin/bash
# CentOS 7 节点一条命令。节点公网 IP 自动识别。主控 IP、安装密钥和 Token 来自主控屏幕上的那条命令。
#
#   curl -fsSL https://github.com/freejbgo/301/raw/main/node.sh -o node.sh && chmod +x node.sh && bash node.sh --master-ip 主控IP --install-key 密钥 --token 后台Token
#
# 识别不到本机公网 IP 时，加上 --node-ip 你的公网IP
set -euo pipefail

REPO_URL="https://github.com/freejbgo/301.git"
SRC="/root/301"

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


MASTER_IP_ARG=""
TOKEN_ARG=""
INSTALL_KEY_B64=""
NODE_IP_ARG=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --master-ip) MASTER_IP_ARG="${2:-}"; shift 2 ;;
    --token) TOKEN_ARG="${2:-}"; shift 2 ;;
    --install-key) INSTALL_KEY_B64="${2:-}"; shift 2 ;;
    --node-ip) NODE_IP_ARG="${2:-}"; shift 2 ;;
    *) die "未知参数: $1" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "请用 root 执行"
[[ -f /etc/os-release ]] || die "找不到 /etc/os-release"
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "centos" && "${VERSION_ID:-}" == 7* ]] || die "这个脚本只用于 CentOS 7.x，当前是 ${ID:-unknown} ${VERSION_ID:-unknown}"

[[ -n "$MASTER_IP_ARG" ]] || die "缺少 --master-ip。请用主控安装完成时打印的整条命令"
[[ -n "$INSTALL_KEY_B64" ]] || die "缺少 --install-key。请用主控安装完成时打印的整条命令"
[[ -n "$TOKEN_ARG" && "$TOKEN_ARG" != "后台Token" ]] || die "请把命令里的 后台Token 换成后台「节点管理」里复制的 Token"
valid_ip "$MASTER_IP_ARG" || die "主控 IP 不是 IPv4: $MASTER_IP_ARG"
is_private "$MASTER_IP_ARG" && die "主控 IP 是内网地址，请用云控制台里的公网 IP"
[[ ! "$TOKEN_ARG" =~ [[:space:]\'\"\`\#\$\\] ]] || die "Token 不要包含空格、引号、#、$ 或反斜杠"
if [[ -n "$NODE_IP_ARG" ]]; then
  valid_ip "$NODE_IP_ARG" || die "节点 IP 不是 IPv4: $NODE_IP_ARG"
  is_private "$NODE_IP_ARG" && die "节点 IP 是内网地址，请填云控制台里的公网 IP"
  [[ "$NODE_IP_ARG" != "$MASTER_IP_ARG" ]] || die "主控和节点要两台机器"
fi

avail_kb="$(df -Pk / | awk 'NR==2 {print $4}')"
[[ "${avail_kb:-0}" -ge 5000000 ]] || die "磁盘剩余不足 5GB。请把系统盘扩到至少 20GB 后再装"

echo "==> CentOS 7 节点安装开始。"
use_centos7_vault
yum clean all
yum makecache
yum install -y git curl ca-certificates openssl iproute openssh-clients

if [[ -n "$NODE_IP_ARG" ]]; then
  NODE_IP="$NODE_IP_ARG"
  DETECTED_VIA="命令里指定的地址"
else
  detect_public_ip || true
  NODE_IP="${DETECTED_IP:-}"
fi
valid_ip "${NODE_IP:-}" || die "没能自动识别本机公网 IP。请在命令末尾加上 --node-ip 你的公网IP"
is_private "$NODE_IP" && die "识别到的是内网 IP ${NODE_IP}。请加上 --node-ip 你的公网IP"
[[ "$NODE_IP" != "$MASTER_IP_ARG" ]] || die "主控和节点要两台机器，两个 IP 不能相同"
echo "==> 本节点公网 IP: ${NODE_IP}（${DETECTED_VIA:-已指定}）"
echo "后台「节点管理」里这条节点的主公网 IP 填 ${NODE_IP}。"

umask 077
keyfile=/root/.redi-node-install.key
printf '%s' "$INSTALL_KEY_B64" | base64 -d > "$keyfile"
chmod 600 "$keyfile"
# 私钥文件应以 -----BEGIN 开头。base64 损坏时这里直接失败。
key_head="$(head -n 1 "$keyfile" || true)"
[[ "$key_head" == *BEGIN* ]] || die "--install-key 无法还原成密钥。请重新复制主控屏幕上的整条命令"

mkdir -p /root/.ssh
chmod 700 /root/.ssh
ssh-keyscan -4 -T 15 "$MASTER_IP_ARG" > /root/.ssh/redi-node-known_hosts 2>/dev/null || true
[[ -s /root/.ssh/redi-node-known_hosts ]] || die "拿不到主控 ${MASTER_IP_ARG} 的 SSH 主机指纹。请确认节点能访问主控的 22 端口"

echo "==> 让主控放行 ${NODE_IP} 连接数据库"
set +e
remote="$(ssh -4 -i "$keyfile" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile=/root/.ssh/redi-node-known_hosts \
  "root@${MASTER_IP_ARG}" "$NODE_IP" 2>/tmp/redi-node-ssh.err)"
ssh_rc=$?
set -e
if [[ "$ssh_rc" -ne 0 ]]; then
  echo "主控没有接受这台节点:" >&2
  cat /tmp/redi-node-ssh.err >&2 || true
  die "请确认用的是主控屏幕上的完整命令，主控允许 root 公钥登录，并且安全组放行了节点访问主控的 22 端口"
fi
DB_PASS="$(printf '%s\n' "$remote" | sed -n "s/^DB_PASS='\\([^']*\\)'/\\1/p")"
DEFAULT_REDIRECT_URL="$(printf '%s\n' "$remote" | sed -n "s/^DEFAULT_REDIRECT_URL='\\([^']*\\)'/\\1/p")"
[[ -n "$DB_PASS" && -n "$DEFAULT_REDIRECT_URL" ]] || die "主控没有返回数据库密码。请把上面的报错保留下来"

echo "==> 下载安装包"
export GIT_TERMINAL_PROMPT=0
if [[ ! -s "${SRC}/pkg/redi-multi.tar.gz" ]]; then
  rm -rf "$SRC"
  if ! git clone --depth 1 "$REPO_URL" "$SRC"; then
    rm -rf "$SRC"
    die "下载代码失败。请先把仓库设为公开，并确认这台机器能访问 GitHub"
  fi
fi
[[ -s "${SRC}/pkg/redi-multi.tar.gz" ]] || die "仓库里没有安装包 pkg/redi-multi.tar.gz"
cp -f "${SRC}/pkg/redi-multi.tar.gz" /tmp/redi-multi.tar.gz

umask 077
cat > "${SRC}/scripts/centos7/vars.env" <<EOF
MASTER_IP="${MASTER_IP_ARG}"
DB_PASS="${DB_PASS}"
NODE_IPS="${NODE_IP}"
REDI_NODE_TAR="/tmp/redi-multi.tar.gz"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
MASTER_URL="http://${MASTER_IP_ARG}"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="${NODE_IP}"
DEFAULT_REDIRECT_URL="${DEFAULT_REDIRECT_URL}"
REDI_MASTER_URL="http://${MASTER_IP_ARG}"
REDI_MASTER_INSECURE="1"
REDI_DB_TLS=""
REDI_MIN_AGENT_VERSION="0.1.3"
REDI_ALLOW_TRUST_ALL_PROXIES="false"
REDI_NODE_TOKEN="${TOKEN_ARG}"
EOF
chmod 600 "${SRC}/scripts/centos7/vars.env"
umask 022

cd "$SRC"
bash scripts/centos7/deploy-node.sh

echo
echo "======== 节点安装完成 ========"
systemctl is-active redi-multi
echo "本节点公网 IP: ${NODE_IP}"
echo "后台节点管理里的主公网 IP 请填这个地址。最后心跳会开始更新。"
echo "主控云安全组的 3306 要允许 ${NODE_IP}。如果刚才数据库连不上，放行后把同一条命令再执行一次。"
