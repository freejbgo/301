#!/usr/bin/env bash
# 静态校验 DEPLOY-centos7.md / 绿场说明与 CentOS 部署脚本、SQL 种子表述一致（防文档漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/DEPLOY-centos7.md"
GREEN="${REPO_ROOT}/docs/GREENFIELD-INSTALL.md"
SQL="${REPO_ROOT}/db/redi_admin.sql"
VARS="${REPO_ROOT}/scripts/centos7/vars.example.env"
DEPLOY_MASTER="${REPO_ROOT}/scripts/centos7/deploy-master.sh"
DEPLOY_NODE="${REPO_ROOT}/scripts/centos7/deploy-node.sh"

fail() {
  echo "verify-deploy-centos7-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing DEPLOY-centos7.md"
[[ -f "$GREEN" ]] || fail "missing GREENFIELD-INSTALL.md"
[[ -f "$SQL" ]] || fail "missing db/redi_admin.sql"

for needle in \
  'scripts/centos7/deploy-master.sh' \
  'scripts/centos7/deploy-node.sh' \
  'scripts/patch-master-cron-ops-webhook.sh' \
  'scripts/centos7/grant-nodes-from-vars.sh' \
  'scripts/centos7/run-on-centos7-host.sh' \
  'scripts/centos7/lagran-diagnose.sh' \
  'scripts/centos7/lagran-cleanup-iptables.sh' \
  'docs/ACCEPTANCE-CENTOS7-DEPLOY.md' \
  'docs/UPGRADE-IN-PLACE.md' \
  'redi_node_bump_skip' \
  '100010_add_node_ipv6_columns' \
  '100011+' \
  'REDI_MASTER_INSECURE' \
  'REDI_DB_TLS' \
  '--max-time=3600' \
  '03:10' \
  '04:10' \
  '08:30' \
  'schedule:run' \
  'redi:requeue-failed-deployments' \
  'CACHE_DRIVER=redis' \
  '/opt/301/master' \
  '/opt/redi-multi/redi-multi'; do
  grep -qF -- "$needle" "$DOC" || fail "DEPLOY-centos7.md missing: ${needle}"
done

grep -qF 'DEPLOY-centos7.md' "${REPO_ROOT}/DEPLOY.md" \
  || fail "DEPLOY.md must link DEPLOY-centos7.md"

grep -qF '100010_add_node_ipv6_columns' "$GREEN" \
  || fail "GREENFIELD-INSTALL.md must mention SQL migration seed 100010_add_node_ipv6_columns"

# SQL 头注释与部署文档中的绿场迁移名一致
grep -q '100010_add_node_ipv6_columns' "$SQL" \
  || fail "redi_admin.sql header must document 100010_add_node_ipv6_columns"
grep -qF '100010_add_node_ipv6_columns' "$DOC" \
  || fail "DEPLOY-centos7.md must match redi_admin.sql seed migration name"

for key in MASTER_INSTALL_ROOT NODE_INSTALL_ROOT MASTER_IP DB_PASS NODE_IPS REDI_MASTER_URL; do
  grep -q "^${key}=" "$VARS" || fail "vars.example.env missing ${key} (DEPLOY §1)"
  grep -qF "$key" "$DOC" || fail "DEPLOY-centos7.md should document vars.env key ${key}"
done

# Cron 时刻与文档 human 表述一致（deploy-master heredoc）
grep -q '10 3 \* \* \*' "$DEPLOY_MASTER" || fail "deploy-master: renew cron must be 03:10"
grep -q '10 4 \* \* \*' "$DEPLOY_MASTER" || fail "deploy-master: prune cron must be 04:10"
grep -q '30 8 \* \* \*' "$DEPLOY_MASTER" || fail "deploy-master: health cron must be 08:30"

grep -q 'redi:ops-webhook --task=redi:renew-certificates' "$DEPLOY_MASTER" \
  || fail "deploy-master: renew cron must ops-webhook --task=redi:renew-certificates"

echo "verify-deploy-centos7-doc: OK"
