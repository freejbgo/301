#!/usr/bin/env bash
# 静态校验 PRODUCTION-READINESS 与仓库门禁表述一致。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/PRODUCTION-READINESS.md"

fail() {
  echo "verify-production-readiness-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing PRODUCTION-READINESS.md"

for needle in \
  'dev-preflight.sh' \
  'REDI_REQUIRE_DNS_POINTING_TO_ENABLE' \
  'redi:ops-webhook' \
  'schedule:run' \
  'master/master' \
  'max-time=3600' \
  'redi:requeue-failed-deployments' \
  'verify-redi-db-deploy-path.sh' \
  '导出 CSV'; do
  grep -qF -- "$needle" "$DOC" || fail "PRODUCTION-READINESS missing: ${needle}"
done

echo "verify-production-readiness-doc: OK"
