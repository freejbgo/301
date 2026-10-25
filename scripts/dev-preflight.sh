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
bash scripts/verify-node-skip-api-invariants.sh
bash scripts/verify-node-api-js-invariants.sh
bash scripts/verify-redirect-conn-limit.sh

echo "==> PHPUnit (master/master, PHP $(php -r 'echo PHP_VERSION;'))"
(
  cd master/master
  composer install --no-interaction --prefer-dist
  ./vendor/bin/phpunit --testsuite Unit,Feature
)

echo "==> 运维 artisan 命令（Cron 对齐）"
bash scripts/verify-redi-artisan-cron.sh

echo "==> masteragent ack / 证书拉取约定"
bash scripts/verify-masteragent-invariants.sh

echo "==> ACME 主机名键约定"
bash scripts/verify-acme-code-invariants.sh

echo "==> 续期 / ops-webhook 约定"
bash scripts/verify-renew-ops-invariants.sh

echo "==> ACME prune / 部署重排队约定"
bash scripts/verify-acme-prune-requeue-invariants.sh

echo "==> Edge DNS 同步约定"
bash scripts/verify-edge-dns-invariants.sh
bash scripts/verify-dns-providers-invariants.sh

echo "==> CNAME 预检 / ACME Job 约定"
bash scripts/verify-cname-cert-job-invariants.sh

echo "==> 跳转 URL / 平台 DNS / force HTTPS 约定"
bash scripts/verify-redirect-platform-invariants.sh

echo "==> Edge Pool 验收清单文档"
bash scripts/verify-acceptance-edge-pool-doc.sh

echo "==> 租户 edge / ACME 队列约定"
bash scripts/verify-tenant-edge-queue-invariants.sh

echo "==> redi:health / 批量域名租户约定"
bash scripts/verify-health-tenant-invariants.sh

echo "==> 证书部署 / 上线文档约定"
bash scripts/verify-cert-deploy-invariants.sh
bash scripts/verify-production-readiness-doc.sh

echo "==> 后台 / 节点 API 限速约定"
bash scripts/verify-admin-api-invariants.sh

echo "==> release.yml / 发布包约定"
bash scripts/verify-release-workflow-invariants.sh

echo "==> 仓库脚本语法 + CentOS 7 部署脚本"
for f in \
  scripts/dev-preflight.sh \
  scripts/ensure-release-tarballs.sh \
  scripts/verify-redi-db-deploy-path.sh \
  scripts/build-master-tar.sh \
  scripts/build-node-tar.sh \
  scripts/patch-master-cron-ops-webhook.sh \
  scripts/verify-master-cron-templates.sh \
  scripts/verify-node-deploy-templates.sh \
  scripts/verify-lagran-iptables-invariants.sh \
  scripts/verify-grant-nodes-templates.sh \
  scripts/verify-acceptance-centos7-doc.sh \
  scripts/verify-deploy-centos7-doc.sh \
  scripts/verify-redi-artisan-cron.sh \
  scripts/verify-masteragent-invariants.sh \
  scripts/verify-acme-code-invariants.sh \
  scripts/verify-renew-ops-invariants.sh \
  scripts/verify-acme-prune-requeue-invariants.sh \
  scripts/verify-edge-dns-invariants.sh \
  scripts/verify-dns-providers-invariants.sh \
  scripts/verify-node-skip-api-invariants.sh \
  scripts/verify-node-api-js-invariants.sh \
  scripts/verify-redirect-conn-limit.sh \
  scripts/verify-cname-cert-job-invariants.sh \
  scripts/verify-redirect-platform-invariants.sh \
  scripts/verify-acceptance-edge-pool-doc.sh \
  scripts/verify-tenant-edge-queue-invariants.sh \
  scripts/verify-health-tenant-invariants.sh \
  scripts/verify-cert-deploy-invariants.sh \
  scripts/verify-production-readiness-doc.sh \
  scripts/verify-admin-api-invariants.sh \
  scripts/verify-release-workflow-invariants.sh \
  scripts/verify-ci-preflight-parity.sh \
  scripts/centos7/*.sh; do
  bash -n "$f"
done
python3 scripts/centos7/lagran_ports.py node/redi-multi/config.json.example | grep -q '80'
python3 - <<'PY'
import sys
sys.path.insert(0, "scripts/centos7")
import lagran_ports as lp
assert lp.resolve_tls_addr({}) == "0.0.0.0:443"
assert lp.resolve_tls_addr({"tls_addr": None}) == ""
assert lp.resolve_tls_addr({"tls_addr": ""}) == ""
assert lp.resolve_tls_addr({"tls_addr": "0.0.0.0:8443"}) == "0.0.0.0:8443"
assert lp.merge_listener_ports("", "0.0.0.0:80", lp.resolve_tls_addr({})) == "80,443"
assert lp.merge_listener_ports("", "0.0.0.0:80", lp.resolve_tls_addr({"tls_addr": None})) == "80"
assert lp.port_from_listen("::80") == "80"
assert lp.merge_listener_ports("", "::80", "") == "80"
import os
os.environ["REDI_TLS_LISTEN"] = "0.0.0.0:8443"
h, t = lp.apply_listen_env_overrides("0.0.0.0:80", "0.0.0.0:443")
assert h == "0.0.0.0:80" and t == "0.0.0.0:8443"
del os.environ["REDI_TLS_LISTEN"]
assert lp.merge_listener_ports("", h, t) == "80,8443"
os.environ["REDI_HTTP_LISTEN"] = ""
h2, _ = lp.apply_listen_env_overrides("0.0.0.0:80", "0.0.0.0:443")
assert h2 == ""
del os.environ["REDI_HTTP_LISTEN"]
PY

echo "==> 主控 Cron / queue unit 模板"
bash scripts/verify-master-cron-templates.sh

echo "==> 节点 deploy / lagran 模板"
bash scripts/verify-node-deploy-templates.sh
bash scripts/verify-lagran-iptables-invariants.sh

echo "==> grant-nodes / vars 模板"
bash scripts/verify-grant-nodes-templates.sh

echo "==> CentOS7 真机验收清单文档"
bash scripts/verify-acceptance-centos7-doc.sh

echo "==> CentOS7 部署教程与脚本"
bash scripts/verify-deploy-centos7-doc.sh

echo "==> SQL 与节点存储过程校验"
php scripts/verify-redi-node-procedure-sync.php
bash scripts/verify-redi-db-deploy-path.sh

echo "==> CI 与 dev-preflight 门禁一致"
bash scripts/verify-ci-preflight-parity.sh

echo "dev-preflight: OK"
