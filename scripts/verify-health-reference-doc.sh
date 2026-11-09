#!/usr/bin/env bash
# HEALTH-REFERENCE 与运营排障表述一致（防重构后 health code 说明漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/HEALTH-REFERENCE.md"
CONSOLE="${REPO_ROOT}/docs/CONSOLE.md"

fail() {
  echo "verify-health-reference-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing HEALTH-REFERENCE.md"
[[ -f "$CONSOLE" ]] || fail "missing CONSOLE.md"

grep -qF 'cert_validating_stuck' "$DOC" || fail "HEALTH-REFERENCE must document cert_validating_stuck"
grep -qE '3 分钟|challenges-synced' "$DOC" \
  || fail "HEALTH-REFERENCE must mention challenges-synced freshness for validating"
grep -qF 'CONSOLE.md' "$DOC" || fail "HEALTH-REFERENCE should link CONSOLE for triage"
grep -qE '3 分钟|challenges-synced' "$CONSOLE" \
  || fail "CONSOLE must document challenges-synced window (see verify-console)"

echo "verify-health-reference-doc: OK"
