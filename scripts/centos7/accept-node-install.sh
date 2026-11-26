#!/bin/bash
# 节点安装公钥的强制命令。调用方只能传入一个公网 IPv4。
# 把这个 IP 加入主控 NODE_IPS 并放行 MySQL / firewalld，再在标准输出打印节点需要的两行配置。
set -euo pipefail

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
  IFS=. read -r a b _ _ <<< "$1"
  if [[ "$a" == "100" && "${b:-}" =~ ^[0-9]+$ ]]; then
    ((10#$b >= 64 && 10#$b <= 127)) && return 0
  fi
  return 1
}

cmd="${SSH_ORIGINAL_COMMAND:-}"
[[ "$cmd" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || die "拒绝: 只能传入一个 IPv4"
valid_ip "$cmd" || die "拒绝: 不是合法 IPv4: $cmd"
is_private "$cmd" && die "拒绝: 内网地址不能放行: $cmd"
NODE_IP="$cmd"

[[ "$(id -u)" -eq 0 ]] || die "请用 root 执行"

SRC="${REDI_INSTALL_SRC:-/root/301}"
VARS="${SRC}/scripts/centos7/vars.env"
[[ -f "$VARS" ]] || die "主控还没有 vars.env，请先把主控装完"

# shellcheck disable=SC1090
source "$VARS"
[[ -n "${DB_PASS:-}" && -n "${MYSQL_ROOT_PASS:-}" ]] || die "vars.env 缺少数据库密码"
if [[ -z "${DEFAULT_REDIRECT_URL:-}" ]]; then
  DEFAULT_REDIRECT_URL="https://www.example.com"
fi

old="${NODE_IPS:-}"
NODE_IPS="$NODE_IP"
for ip in $old; do
  [[ -z "$ip" || "$ip" == "$NODE_IP" ]] && continue
  case " ${NODE_IPS} " in
    *" ${ip} "*) ;;
    *) NODE_IPS="${NODE_IPS} ${ip}" ;;
  esac
done

if grep -q '^NODE_IPS=' "$VARS"; then
  sed -i "s/^NODE_IPS=.*/NODE_IPS=\"${NODE_IPS}\"/" "$VARS"
else
  echo "NODE_IPS=\"${NODE_IPS}\"" >> "$VARS"
fi

bash "${SRC}/scripts/centos7/grant-nodes-from-vars.sh" >&2

printf "DB_PASS='%s'\n" "$DB_PASS"
printf "DEFAULT_REDIRECT_URL='%s'\n" "$DEFAULT_REDIRECT_URL"
