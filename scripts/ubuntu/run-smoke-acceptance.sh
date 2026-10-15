#!/usr/bin/env bash
# 单机 Ubuntu 主控+节点脚本冒烟（不含 F1–F8）。用法: sudo bash scripts/ubuntu/run-smoke-acceptance.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
[[ "$(id -u)" -eq 0 ]] || { echo "请 sudo 执行" >&2; exit 1; }

IP="$(hostname -I | awk '{print $1}')"
[[ -n "$IP" ]] || { echo "无法检测本机 IP" >&2; exit 1; }

VARS="${ROOT}/.redi-smoke-vars.env"
cat > "$VARS" <<EOF
MASTER_IP="${IP}"
DB_PASS="SmokeDbPass_2026_x"
MYSQL_ROOT_PASS="SmokeRootPass_2026_x"
NODE_IPS="${IP}"
MASTER_INSTALL_ROOT="/opt/301"
NODE_INSTALL_ROOT="/opt/redi-multi"
REDI_MASTER_TAR="${ROOT}/master/master.tar.gz"
REDI_NODE_TAR="${ROOT}/node/redi-multi.tar.gz"
MASTER_URL="http://${IP}"
ADMIN_HTTPS="false"
NODE_PUBLIC_IP="${IP}"
DEFAULT_REDIRECT_URL="https://www.example.com"
REDI_MASTER_INSECURE="1"
REDI_MASTER_URL="http://${IP}"
REDI_NODE_TOKEN=""
REDI_SMOKE_SINGLE_HOST="1"
EOF
chmod 600 "$VARS"

export PATH="/usr/local/bin:/usr/bin:$PATH"
if ! command -v composer >/dev/null; then
  if ! command -v php >/dev/null; then
    apt-get update -qq
    apt-get install -y -qq php-cli php-xml php-mbstring php-zip unzip curl
  fi
  [[ -x /usr/local/bin/composer ]] || { curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer; }
fi
if [[ ! -f "${ROOT}/master/master.tar.gz" ]]; then
  echo "==> 构建发布包"
  if ! command -v go >/dev/null; then
    apt-get update -qq
    apt-get install -y -qq golang-go
  fi
  (cd "${ROOT}/master/master" && composer install --no-interaction --prefer-dist --no-dev -q)
  "${ROOT}/scripts/build-master-tar.sh"
  "${ROOT}/scripts/build-node-tar.sh"
fi

echo "==> deploy-master"
VARS_FILE="$VARS" bash "${ROOT}/scripts/ubuntu/deploy-master.sh"
redi_wait_mysql() {
  local h="$1" i
  for i in $(seq 1 90); do
    (echo >/dev/tcp/"$h"/3306) >/dev/null 2>&1 && return 0
    sleep 1
  done
  return 1
}
# shellcheck source=lib.sh
source "${ROOT}/scripts/ubuntu/lib.sh"
redi_restart mariadb 2>/dev/null || service mysql start 2>/dev/null || true
redi_wait_mysql "$IP" || { echo "MariaDB 未监听 3306" >&2; exit 1; }

echo "==> deploy-node"
VARS_FILE="$VARS" bash "${ROOT}/scripts/ubuntu/deploy-node.sh"

APP="/opt/301/master"
NODE="/opt/redi-multi/redi-multi"
fail=0
check() { if "$@"; then echo "[PASS] $1"; else echo "[FAIL] $1" >&2; fail=1; fi; }

check artisan test -f "${APP}/artisan"
check procedure mysql --defaults-extra-file=/root/.redi-mysql.cnf -N -e \
  "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='redi_admin' AND ROUTINE_NAME='redi_node_bump_skip'" | grep -q '^1$'
code=$(curl -sI -o /dev/null -w '%{http_code}' "http://127.0.0.1/admin" || echo 0)
check admin_http test "$code" = "302" -o "$code" = "200"
check redi-multi pgrep -f "${NODE}/bin/redi-multi" >/dev/null
check node_mysql mysql -h "$IP" -u redi_node -p'SmokeDbPass_2026_x' redi_admin -e "CALL redi_node_bump_skip('__accept__')" >/dev/null

[[ "$fail" -eq 0 ]] && echo "Ubuntu 脚本化冒烟通过（${IP}）。F1–F8 须公网另验。" && exit 0
exit 1
