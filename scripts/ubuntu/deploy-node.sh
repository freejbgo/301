#!/usr/bin/env bash
# Ubuntu 22.04 / 24.04 LTS 节点一键部署（静态 redi-multi 二进制）。
#
#   cp scripts/ubuntu/vars.example.env scripts/ubuntu/vars.env
#   sudo bash scripts/ubuntu/deploy-node.sh
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

[[ "$(id -u)" -ne 0 ]] && die "请用 root 执行"
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "此脚本仅用于 Ubuntu"
redi_ubuntu_php_version "${VERSION_ID:-}" >/dev/null || die "仅支持 Ubuntu 22.04 / 24.04"

[[ -f "$VARS_FILE" ]] || die "缺少 vars.env"
# shellcheck disable=SC1090
source "$VARS_FILE"

TAR="${REDI_NODE_TAR:-}"
: "${MASTER_IP:?}"
: "${DB_PASS:?}"
: "${NODE_PUBLIC_IP:?}"
: "${NODE_INSTALL_ROOT:?}"
: "${DEFAULT_REDIRECT_URL:?}"
: "${REDI_MASTER_URL:?}"
REDI_NODE_TOKEN="${REDI_NODE_TOKEN:-}"

reject_text() {
  local name="$1" value="$2"
  [[ -z "$value" ]] && return 0
  if [[ "$value" == *$'\n'* || "$value" =~ [[:space:]\'\"\`\#\$\\] ]]; then
    die "${name} 含非法字符"
  fi
}
reject_text "DB_PASS" "$DB_PASS"
reject_text "DEFAULT_REDIRECT_URL" "$DEFAULT_REDIRECT_URL"
reject_text "REDI_MASTER_URL" "$REDI_MASTER_URL"
reject_text "REDI_NODE_TOKEN" "$REDI_NODE_TOKEN"
redi_is_ipv4 "$MASTER_IP" || die "MASTER_IP 须为 IPv4"
redi_is_ipv4 "$NODE_PUBLIC_IP" || die "NODE_PUBLIC_IP 须为 IPv4"

REDI_MASTER_INSECURE="${REDI_MASTER_INSECURE:-}"
REDI_DB_TLS="${REDI_DB_TLS:-}"
if [[ "$REDI_MASTER_URL" == https://* ]]; then
  :
elif [[ "$REDI_MASTER_URL" == http://* && "$REDI_MASTER_INSECURE" == "1" ]]; then
  echo "警告: REDI_MASTER_INSECURE=1（仅 lab）"
else
  die "REDI_MASTER_URL 须 https://；lab 可 REDI_MASTER_INSECURE=1 + http://"
fi
[[ "$DEFAULT_REDIRECT_URL" == http://* || "$DEFAULT_REDIRECT_URL" == https://* ]] || die "DEFAULT_REDIRECT_URL 须 http(s)://"

[[ -z "$TAR" && -f "${REPO_ROOT}/node/redi-multi.tar.gz" ]] && TAR="${REPO_ROOT}/node/redi-multi.tar.gz"
[[ -z "$TAR" && -f /tmp/redi-multi.tar.gz ]] && TAR="/tmp/redi-multi.tar.gz"
[[ -n "$TAR" && -f "$TAR" ]] || die "找不到 redi-multi.tar.gz"

step "安装依赖"
apt-get update -qq
apt-get install -y -qq ufw tar libcap2-bin mariadb-client python3 iptables

modprobe nfnetlink_queue 2>/dev/null || echo "警告: nfnetlink_queue 未单独加载（可能已编进内核）"

step "ufw：22、80、443"
redi_ufw_bootstrap

APP="${NODE_INSTALL_ROOT}/redi-multi"
step "解压到 ${APP}"
mkdir -p "$NODE_INSTALL_ROOT"
tar xzf "$TAR" -C "$NODE_INSTALL_ROOT"
[[ -x "${APP}/bin/redi-multi" ]] || die "缺少 bin/redi-multi"
[[ -f "${APP}/config.json" ]] || cp "${APP}/config.json.example" "${APP}/config.json"

umask 077
{
  echo "REDI_DB_HOST=${MASTER_IP}"
  echo "REDI_DB_PORT=3306"
  echo "REDI_DB_USER=redi_node"
  echo "REDI_DB_PASSWORD=${DB_PASS}"
  echo "REDI_DB_NAME=redi_admin"
  [[ -n "$REDI_DB_TLS" ]] && echo "REDI_DB_TLS=${REDI_DB_TLS}"
  echo "REDI_MASTER_URL=${REDI_MASTER_URL}"
  [[ "$REDI_MASTER_INSECURE" == "1" ]] && echo "REDI_MASTER_INSECURE=1"
  echo "REDI_NODE_TOKEN=${REDI_NODE_TOKEN}"
} > "${APP}/env.sh"
chmod 600 "${APP}/env.sh"

url_sed=${DEFAULT_REDIRECT_URL//&/\\&}
sed -i -E "s/\"server_ip\"[[:space:]]*:[[:space:]]*\"[^\"]*\"/\"server_ip\": \"${NODE_PUBLIC_IP}\"/" "${APP}/config.json"
sed -i -E "s|\"default_redirect_url\"[[:space:]]*:[[:space:]]*\"[^\"]*\"|\"default_redirect_url\": \"${url_sed}\"|" "${APP}/config.json"
redi_ensure_lagran_enabled "${APP}/config.json" || die "lagran.enabled"
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
if redi_systemd_available; then
  systemctl daemon-reload
  systemctl enable --now redi-multi
  sleep 2
  systemctl is-active --quiet redi-multi || die "redi-multi 未运行"
else
  pkill -f "${APP}/bin/redi-multi" 2>/dev/null || true
  # shellcheck disable=SC1091
  set -a && source "${APP}/env.sh" && set +a
  nohup "${APP}/bin/redi-multi" >> "${APP}/redi-multi.log" 2>&1 &
  sleep 2
  pgrep -f "${APP}/bin/redi-multi" >/dev/null || die "redi-multi 未运行"
fi

db_cnf=$(mktemp)
chmod 600 "$db_cnf"
cat > "$db_cnf" <<EOF
[client]
host=${MASTER_IP}
user=redi_node
password=${DB_PASS}
database=redi_admin
EOF
mysql --defaults-extra-file="$db_cnf" -e "CALL redi_node_bump_skip('__smoke__')" >/dev/null \
  || die "无法连主控 MySQL 或 redi_node 无 EXECUTE 权限"
rm -f "$db_cnf"

echo "节点已安装到 ${APP}"
[[ -z "$REDI_NODE_TOKEN" ]] && echo "请填写 REDI_NODE_TOKEN 后重跑本脚本"
