#!/usr/bin/env bash
# CentOS 7.x（推荐 7.9）节点全自动部署。程序是静态二进制，内核不需要重编，也不编译内核模块。
#
# 主控上的 deploy-master.sh 已经把这台机器的公网 IP 写入 NODE_IPS 之后再执行。
#   cp scripts/centos7/vars.example.env scripts/centos7/vars.env
#   chmod 600 scripts/centos7/vars.env
#   填 NODE_PUBLIC_IP、REDI_MASTER_URL、REDI_NODE_TOKEN
#   sudo bash scripts/centos7/deploy-node.sh
#
# 发布包查找顺序：环境变量 REDI_NODE_TAR、仓库里的 node/redi-multi.tar.gz、/tmp/redi-multi.tar.gz。
# 重复执行会按 vars.env 重写 env.sh，并更新 config.json 的 server_ip 和 default_redirect_url。
set -euo pipefail
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
VARS_FILE="${VARS_FILE:-${SCRIPT_DIR}/vars.env}"

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
  die "请先复制并编辑 vars.example.env 为 vars.env"
fi
# shellcheck disable=SC1090
source "$VARS_FILE"

TAR="${REDI_NODE_TAR:-}"

: "${MASTER_IP:?vars.env 需要 MASTER_IP}"
: "${DB_PASS:?vars.env 需要 DB_PASS}"
: "${NODE_PUBLIC_IP:?vars.env 需要 NODE_PUBLIC_IP}"
: "${NODE_INSTALL_ROOT:?vars.env 需要 NODE_INSTALL_ROOT}"
: "${DEFAULT_REDIRECT_URL:?vars.env 需要 DEFAULT_REDIRECT_URL}"
: "${REDI_MASTER_URL:?vars.env 需要 REDI_MASTER_URL}"
REDI_NODE_TOKEN="${REDI_NODE_TOKEN:-}"

reject_text() {
  local name="$1" value="$2"
  if [[ -z "$value" ]]; then
    return 0
  fi
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

reject_text "DB_PASS" "$DB_PASS"
reject_text "DEFAULT_REDIRECT_URL" "$DEFAULT_REDIRECT_URL"
reject_text "REDI_MASTER_URL" "$REDI_MASTER_URL"
reject_text "REDI_NODE_TOKEN" "$REDI_NODE_TOKEN"
reject_text "NODE_INSTALL_ROOT" "$NODE_INSTALL_ROOT"
is_ipv4 "$MASTER_IP" || die "MASTER_IP 必须是 IPv4"
is_ipv4 "$NODE_PUBLIC_IP" || die "NODE_PUBLIC_IP 必须是 IPv4"
[[ "$REDI_MASTER_URL" == https://* ]] || die "REDI_MASTER_URL 必须使用 https://（节点 Agent 传输证书私钥）"
[[ "$DEFAULT_REDIRECT_URL" == http://* || "$DEFAULT_REDIRECT_URL" == https://* ]] || die "DEFAULT_REDIRECT_URL 要以 http:// 或 https:// 开头"
[[ "$DEFAULT_REDIRECT_URL" != *'|'* ]] || die "DEFAULT_REDIRECT_URL 不要包含 |"

if [[ -z "$TAR" && -f "${REPO_ROOT}/node/redi-multi.tar.gz" ]]; then
  TAR="${REPO_ROOT}/node/redi-multi.tar.gz"
fi
if [[ -z "$TAR" && -f /tmp/redi-multi.tar.gz ]]; then
  TAR="/tmp/redi-multi.tar.gz"
fi
[[ -n "$TAR" && -f "$TAR" ]] || die "找不到节点发布包。设置 REDI_NODE_TAR，或把 redi-multi.tar.gz 放到 /tmp"

step "CentOS $(uname -r)。使用静态二进制，不编译内核模块"
redi_use_centos7_vault_repos
yum install -y epel-release
redi_use_epel_archive_repo
yum install -y firewalld tar iptables libcap mariadb

if modprobe nfnetlink_queue 2>/dev/null; then
  echo "已加载 nfnetlink_queue"
else
  echo "警告: 未能单独加载 nfnetlink_queue。该功能若已编进当前内核，可以忽略。lagran 不可用时，普通跳转仍然工作。"
fi

step "防火墙只开放 22、80、443"
systemctl enable --now firewalld
add_svc() {
  firewall-cmd --permanent --query-service="$1" >/dev/null \
    || firewall-cmd --permanent --add-service="$1"
}
add_svc ssh
add_svc http
add_svc https
firewall-cmd --reload

APP="${NODE_INSTALL_ROOT}/redi-multi"
step "解压到 ${APP}"
mkdir -p "$NODE_INSTALL_ROOT"
tar xzf "$TAR" -C "$NODE_INSTALL_ROOT"
[[ -x "${APP}/bin/redi-multi" ]] || die "解压后没有 ${APP}/bin/redi-multi"
if [[ ! -f "${APP}/config.json" ]]; then
  cp "${APP}/config.json.example" "${APP}/config.json"
fi
if ldd "${APP}/bin/redi-multi" 2>&1 | grep -q 'GLIBC\|libc.so'; then
  die "这个二进制仍依赖动态 libc，不能在 CentOS 7 上运行。请换仓库里重新打包的 node/redi-multi.tar.gz"
fi

prev_umask=$(umask)
umask 077
cat > "${APP}/env.sh" <<EOF
REDI_DB_HOST=${MASTER_IP}
REDI_DB_PORT=3306
REDI_DB_USER=redi_node
REDI_DB_PASSWORD=${DB_PASS}
REDI_DB_NAME=redi_admin
REDI_MASTER_URL=${REDI_MASTER_URL}
REDI_NODE_TOKEN=${REDI_NODE_TOKEN}
EOF
chmod 600 "${APP}/env.sh"
umask "$prev_umask"

[[ -f "${APP}/config.json" ]] || cp "${APP}/config.json.example" "${APP}/config.json"
url_sed=${DEFAULT_REDIRECT_URL//&/\\&}
sed -i -E "s/\"server_ip\"[[:space:]]*:[[:space:]]*\"[^\"]*\"/\"server_ip\": \"${NODE_PUBLIC_IP}\"/" "${APP}/config.json"
sed -i -E "s|\"default_redirect_url\"[[:space:]]*:[[:space:]]*\"[^\"]*\"|\"default_redirect_url\": \"${url_sed}\"|" "${APP}/config.json"
grep -q "\"server_ip\": \"${NODE_PUBLIC_IP}\"" "${APP}/config.json" || die "未能写入 config.json 的 server_ip"

setcap cap_net_admin+ep "${APP}/bin/redi-multi"

cat > /etc/systemd/system/redi-multi.service <<EOF
[Unit]
Description=301 redirect node (redi-multi)
After=network.target

[Service]
Type=simple
WorkingDirectory=${APP}
EnvironmentFile=${APP}/env.sh
ExecStart=${APP}/bin/redi-multi
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now redi-multi
systemctl restart redi-multi
active=0
for _ in 1 2 3 4 5 6 7 8; do
  if systemctl is-active --quiet redi-multi; then
    active=1
    break
  fi
  sleep 1
done
if [[ "$active" != 1 ]]; then
  systemctl status redi-multi --no-pager || true
  die "redi-multi 没有保持运行。80 或 443 可能已被占用"
fi

step "测试到主控数据库的连接"
db_cnf=$(mktemp)
chmod 600 "$db_cnf"
cat > "$db_cnf" <<EOF
[client]
host=${MASTER_IP}
user=redi_admin
password=${DB_PASS}
EOF
if mysql --defaults-extra-file="$db_cnf" -e "SELECT 1" redi_admin >/dev/null; then
  echo "数据库连接正常"
else
  rm -f "$db_cnf"
  die "连不上 ${MASTER_IP} 的 redi_admin。请确认主控已对本机 IP 授权并放行 3306"
fi
rm -f "$db_cnf"

echo
echo "节点已安装到 ${APP}"
if [[ -z "$REDI_NODE_TOKEN" ]]; then
  echo "REDI_NODE_TOKEN 还是空的。后台创建节点后把 Token 写进 vars.env，再执行一次本脚本。"
  echo "Token 为空时进程可以跳转，但不会向主控注册，也不能同步证书。"
else
  echo "已写入 Token。查看状态: systemctl status redi-multi"
fi
echo "同一台机器的其他公网 IP 填在后台该节点的「其他公网 IP」，不要再部署第二个进程。"
