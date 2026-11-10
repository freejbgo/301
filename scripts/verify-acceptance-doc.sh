#!/usr/bin/env bash
# 静态校验合并后的验收清单与 Cron/Edge 关键表述一致。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/ACCEPTANCE.md"

fail() {
  echo "verify-acceptance-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing ACCEPTANCE.md"

for needle in \
  'redi:ops-webhook' \
  '--task=redi:renew-certificates' \
  '--task=redi:prune-acme-challenges' \
  'redi:prune-acme-challenges' \
  '--task=redi:health' \
  'redi:requeue-failed-deployments' \
  'schedule:run' \
  'master/master' \
  'grant-nodes-from-vars' \
  'redi_node' \
  'dev-preflight.sh' \
  '--max-time=3600' \
  'dns_api_tenants_unsynced' \
  '同步失败后的检查' \
  'redi:renew-certificates' \
  'redi:health --notify' \
  'REDI_PUBLISH_POOL_AAAA' \
  '没有启用的节点公网 IPv4' \
  '## A. CentOS 7.9' \
  '## B. 边缘池业务验收'; do
  grep -qF -- "$needle" "$DOC" || fail "ACCEPTANCE.md missing: ${needle}"
done

grep -qF 'PLATFORM-ARCHITECTURE.md' "$DOC" \
  || fail 'ACCEPTANCE.md must link PLATFORM-ARCHITECTURE in §B'

echo "verify-acceptance-doc: OK"
