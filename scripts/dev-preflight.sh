#!/usr/bin/env bash
# 开发机发版前自检，与 .github/workflows/ci.yml 的 node + centos7-scripts 核心步骤对齐。
# 完整说明见 docs/AUDIT-CENTOS7-PRODUCTION.md §9（含 PHP 8.0 job、容器冒烟、composer audit）。
# 用法: bash scripts/dev-preflight.sh [--help]
set -euo pipefail

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  echo "用法: bash scripts/dev-preflight.sh"
  echo "说明: docs/AUDIT-CENTOS7-PRODUCTION.md §9"
  exit 0
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

echo "==> Go vet + test (node/redi-multi)"
(
  cd node/redi-multi
  go vet ./...
  go test ./...
)

echo "==> PHPUnit (master/master, PHP $(php -r 'echo PHP_VERSION;'))"
(
  cd master/master
  composer install --no-interaction --prefer-dist
  ./vendor/bin/phpunit --testsuite Unit,Feature
)

echo "==> 仓库脚本语法 + CentOS 7 部署脚本"
for f in \
  scripts/dev-preflight.sh \
  scripts/ensure-release-tarballs.sh \
  scripts/verify-redi-db-deploy-path.sh \
  scripts/build-master-tar.sh \
  scripts/build-node-tar.sh \
  scripts/patch-master-cron-ops-webhook.sh \
  scripts/centos7/*.sh; do
  bash -n "$f"
done
python3 scripts/centos7/lagran_ports.py node/redi-multi/config.json.example | grep -q '80'

echo "==> SQL 与节点存储过程校验"
php scripts/verify-redi-node-procedure-sync.php
bash scripts/verify-redi-db-deploy-path.sh

echo "dev-preflight: OK"
