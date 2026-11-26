#!/usr/bin/env bash
# 在跑主控且 Cron 落后于当前模板时：将 /etc/cron.d/redi-renew 改为带 ops-webhook 失败告警的写法。
# deploy-master.sh 已写入正确 cron，首装勿用本脚本。
# 用法: sudo bash scripts/patch-master-cron-ops-webhook.sh [/opt/301/master]
# 发布包部署（deploy-master）程序目录为 /opt/301/master；勿与仓库克隆路径 master/master 混淆。
set -euo pipefail

CRON_FILE="/etc/cron.d/redi-renew"

[[ "$(id -u)" -eq 0 ]] || { echo "请用 root 执行: sudo bash scripts/patch-master-cron-ops-webhook.sh" >&2; exit 1; }

if [[ -n "${1:-}" ]]; then
  APP="$1"
elif [[ -f /opt/301/master/artisan ]]; then
  APP=/opt/301/master
elif [[ -f /opt/301/master/master/artisan ]]; then
  APP=/opt/301/master/master
  echo "警告: 检测到 ${APP}，CentOS 发布包标准路径为 /opt/301/master" >&2
else
  APP=/opt/301/master
fi

[[ -f "${APP}/artisan" ]] || { echo "找不到 artisan: ${APP}" >&2; exit 1; }

if id nginx &>/dev/null; then
  CRON_USER="nginx"
elif id www-data &>/dev/null; then
  CRON_USER="www-data"
else
  echo "未找到 nginx 或 www-data 用户" >&2
  exit 1
fi

cat > "$CRON_FILE" <<EOF
SHELL=/bin/bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
10 3 * * * ${CRON_USER} bash -c 'cd ${APP} && /usr/bin/php artisan redi:renew-certificates >> ${APP}/storage/logs/renew.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:renew-certificates >> ${APP}/storage/logs/renew.log 2>&1'
10 4 * * * ${CRON_USER} bash -c 'cd ${APP} && /usr/bin/php artisan redi:prune-acme-challenges >> ${APP}/storage/logs/prune-acme.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:prune-acme-challenges >> ${APP}/storage/logs/prune-acme.log 2>&1'
30 8 * * * ${CRON_USER} bash -c 'cd ${APP} && /usr/bin/php artisan redi:health --notify >> ${APP}/storage/logs/health.log 2>&1 || /usr/bin/php artisan redi:ops-webhook --task=redi:health >> ${APP}/storage/logs/health.log 2>&1'
EOF
chmod 644 "$CRON_FILE"

echo "已写入 ${CRON_FILE}（用户 ${CRON_USER}，APP=${APP}）"
echo "请确认:"
echo "  grep -E 'renew|prune|health' ${CRON_FILE}"
echo "  ! grep -q 'schedule:run' ${CRON_FILE}"
echo "  ! grep -q 'master/master' ${CRON_FILE}"
echo "  cd ${APP} && sudo -u ${CRON_USER} php artisan redi:health"
echo "  cd ${APP} && sudo -u ${CRON_USER} php artisan redi:health --notify; echo exit=\$?"
echo "  cd ${APP} && sudo -u ${CRON_USER} php artisan list | grep redi:ops-webhook"
echo "  （建议 .env 配置 REDI_HEALTH_WEBHOOK_URL，08:30 --notify 才能在有 issues 时退出 0）"
