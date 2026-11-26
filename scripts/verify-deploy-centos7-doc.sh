#!/usr/bin/env bash
# 静态校验 DEPLOY-centos7.md / 绿场说明与 CentOS 部署脚本、SQL 种子表述一致（防文档漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/DEPLOY-centos7.md"
SQL="${REPO_ROOT}/db/redi_admin.sql"
VARS="${REPO_ROOT}/scripts/centos7/vars.example.env"
VARS_PROD="${REPO_ROOT}/scripts/centos7/vars.production.example.env"
DEPLOY_MASTER="${REPO_ROOT}/scripts/centos7/deploy-master.sh"
DEPLOY_NODE="${REPO_ROOT}/scripts/centos7/deploy-node.sh"

fail() {
  echo "verify-deploy-centos7-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing DEPLOY-centos7.md"
[[ -f "$SQL" ]] || fail "missing db/redi_admin.sql"

for needle in \
  'scripts/centos7/deploy-master.sh' \
  'scripts/centos7/deploy-node.sh' \
  'scripts/patch-master-cron-ops-webhook.sh' \
  'scripts/centos7/grant-nodes-from-vars.sh' \
  'scripts/centos7/run-on-centos7-host.sh' \
  'scripts/centos7/lagran-diagnose.sh' \
  'scripts/centos7/lagran-cleanup-iptables.sh' \
  'docs/ACCEPTANCE.md' \
  'docs/OPS-RELEASE-UPDATE.md' \
  'build-release-tarballs.sh' \
  'REDI_MASTER_TAR' \
  '不需要 Docker' \
  '同步失败后的检查' \
  'redi_node_bump_skip' \
  '不必手工 migrate' \
  'OPS-RELEASE-UPDATE.md' \
  'REDI_MASTER_INSECURE' \
  'REDI_DB_TLS' \
  'REDI_AGENT_EMPTY_CERT_CLEAR_AFTER' \
  'REDI_AGENT_EMPTY_CHALLENGE_CLEAR_AFTER' \
  'REDI_AGENT_EMPTY_RULES_CLEAR_AFTER' \
  'redi_mysql_tls_args_from_env' \
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

grep -qF 'docs/I18N.md' "$DEPLOY_MASTER" \
  || fail "deploy-master.sh must mention docs/I18N.md"
grep -qF 'redi_admin.sql' "$DOC" \
  || fail "DEPLOY-centos7.md must document redi_admin.sql"
grep -qF '不要只跑' "$DOC" \
  || fail "DEPLOY-centos7.md must warn against migrate-only on an empty database"

grep -qF 'redi_node_bump_skip' "$SQL" \
  || fail "redi_admin.sql must define or reference redi_node_bump_skip"

[[ -f "$VARS_PROD" ]] || fail "missing vars.production.example.env"
grep -qF 'REDI_MASTER_TAR=' "$VARS_PROD" || fail "vars.production.example.env must set REDI_MASTER_TAR"
grep -qF 'vars.production.example.env' "${REPO_ROOT}/README.md" \
  || fail "README must link vars.production.example.env"
grep -qF 'REDI_ALLOW_ON_SERVER_TAR_BUILD' "${REPO_ROOT}/scripts/ensure-release-tarballs.sh" \
  || fail "ensure-release-tarballs must gate on-server build"

for key in MASTER_INSTALL_ROOT NODE_INSTALL_ROOT MASTER_IP DB_PASS NODE_IPS REDI_MASTER_URL \
  NODE_PUBLIC_IP DEFAULT_REDIRECT_URL; do
  grep -q "^${key}=" "$VARS" || fail "vars.example.env missing ${key} (DEPLOY §1)"
  grep -qF "$key" "$DOC" || fail "DEPLOY-centos7.md should document vars.env key ${key}"
done

grep -q 'MASTER_IP:?' "$DEPLOY_NODE" || fail "deploy-node.sh must require MASTER_IP"
grep -q 'DB_PASS:?' "$DEPLOY_NODE" || fail "deploy-node.sh must require DB_PASS"
grep -qF 'vars.production.example.env' "$DOC" \
  || fail "DEPLOY-centos7.md must mention vars.production.example.env"

# Cron 时刻与文档 human 表述一致（deploy-master heredoc）
grep -q '10 3 \* \* \*' "$DEPLOY_MASTER" || fail "deploy-master: renew cron must be 03:10"
grep -q '10 4 \* \* \*' "$DEPLOY_MASTER" || fail "deploy-master: prune cron must be 04:10"
grep -q '30 8 \* \* \*' "$DEPLOY_MASTER" || fail "deploy-master: health cron must be 08:30"

grep -q 'redi:ops-webhook --task=redi:renew-certificates' "$DEPLOY_MASTER" \
  || fail "deploy-master: renew cron must ops-webhook --task=redi:renew-certificates"

echo "verify-deploy-centos7-doc: OK"
