#!/usr/bin/env bash
# 确认 Cron / 运维文档引用的 artisan 命令已注册（防改名后 Cron 静默失败）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master"

fail() {
  echo "verify-redi-artisan-cron: $*" >&2
  exit 1
}

[[ -f "${APP}/artisan" ]] || fail "missing master/master/artisan"
[[ -f "${APP}/vendor/autoload.php" ]] || fail "run composer install in master/master first"

cd "$APP"
commands=(
  redi:renew-certificates
  redi:prune-acme-challenges
  redi:health
  redi:ops-webhook
  redi:requeue-failed-deployments
)
for cmd in "${commands[@]}"; do
  php artisan "$cmd" --help >/dev/null 2>&1 || fail "artisan command not registered: ${cmd}"
done

php artisan redi:health --help 2>&1 | grep -q -- '--notify' \
  || fail "redi:health must support --notify (Cron 08:30)"

echo "verify-redi-artisan-cron: OK"
