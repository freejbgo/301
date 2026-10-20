#!/usr/bin/env bash
# 静态校验 grant-nodes / vars 与 deploy-master 的 NODE_IPS 同步路径一致。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

fail() {
  echo "verify-grant-nodes-templates: $*" >&2
  exit 1
}

GRANT="${REPO_ROOT}/scripts/centos7/grant-nodes-from-vars.sh"
DEPLOY_MASTER="${REPO_ROOT}/scripts/centos7/deploy-master.sh"
LIB="${REPO_ROOT}/scripts/centos7/lib.sh"
VARS="${REPO_ROOT}/scripts/centos7/vars.example.env"

[[ -f "$GRANT" ]] || fail "missing grant-nodes-from-vars.sh"
[[ -f "$DEPLOY_MASTER" ]] || fail "missing deploy-master.sh"
[[ -f "$LIB" ]] || fail "missing lib.sh"
[[ -f "$VARS" ]] || fail "missing vars.example.env"

grep -q 'redi_sync_node_ips_access' "$LIB" || fail "lib.sh: missing redi_sync_node_ips_access"
grep -q 'redi_sync_node_ips_access' "$GRANT" || fail "grant-nodes: must call redi_sync_node_ips_access"
grep -q 'redi_sync_node_ips_access' "$DEPLOY_MASTER" || fail "deploy-master: must call redi_sync_node_ips_access"

grep -q 'id -u' "$GRANT" || fail "grant-nodes: must require root"
grep -q 'MYSQL_ROOT_PASS' "$GRANT" || fail "grant-nodes: must read MYSQL_ROOT_PASS from vars"
grep -q 'DB_PASS' "$GRANT" || fail "grant-nodes: must read DB_PASS from vars"

for key in NODE_IPS MYSQL_ROOT_PASS DB_PASS; do
  grep -q "^${key}=" "$VARS" || fail "vars.example.env: missing ${key}"
done

grep -q 'grant-nodes-from-vars' "$DEPLOY_MASTER" || fail "deploy-master: should mention grant-nodes-from-vars in ops hint"

echo "verify-grant-nodes-templates: OK"
