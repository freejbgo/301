#!/usr/bin/env bash
# 静态校验 F1–F8 验收清单与 Cron/Edge 关键表述一致。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/ACCEPTANCE-EDGE-POOL.md"

fail() {
  echo "verify-acceptance-edge-pool-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing ACCEPTANCE-EDGE-POOL.md"

for needle in \
  'redi:ops-webhook' \
  'schedule:run' \
  'redi:renew-certificates' \
  'redi:prune-acme-challenges' \
  'redi:health --notify' \
  '--max-time=3600' \
  'REDI_PUBLISH_POOL_AAAA' \
  '没有启用的节点公网 IPv4'; do
  grep -qF -- "$needle" "$DOC" || fail "ACCEPTANCE-EDGE-POOL doc missing: ${needle}"
done

echo "verify-acceptance-edge-pool-doc: OK"
