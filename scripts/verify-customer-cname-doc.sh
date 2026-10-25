#!/usr/bin/env bash
# 客户 CNAME 说明与运营附录、CONSOLE 互链（防漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CUSTOMER="${REPO_ROOT}/docs/CUSTOMER-CNAME-HTTPS.md"
CONSOLE="${REPO_ROOT}/docs/CONSOLE.md"

fail() {
  echo "verify-customer-cname-doc: $*" >&2
  exit 1
}

[[ -f "$CUSTOMER" ]] || fail "missing CUSTOMER-CNAME-HTTPS.md"
[[ -f "$CONSOLE" ]] || fail "missing CONSOLE.md"

grep -q '您需要做的' "$CUSTOMER" || fail "customer doc must keep end-user section"
grep -q '平台运营人员' "$CUSTOMER" || fail "customer doc must have operator appendix"
grep -q '同步失败后的检查' "$CUSTOMER" || fail "operator appendix must link CONSOLE DNS sync checklist"
grep -q 'ACCEPTANCE-EDGE-POOL' "$CUSTOMER" || fail "operator appendix must link EDGE-POOL acceptance"
grep -q 'CUSTOMER-CNAME-HTTPS.md' "$CONSOLE" || fail "CONSOLE must link customer CNAME doc"

echo "verify-customer-cname-doc: OK"
