#!/usr/bin/env bash
# LAUNCH-CHECKLIST 与 PRODUCTION-READINESS 互链、含 A–E 勾选项。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/LAUNCH-CHECKLIST.md"
PROD="${REPO_ROOT}/docs/PRODUCTION-READINESS.md"

fail() {
  echo "verify-launch-checklist-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing LAUNCH-CHECKLIST.md"
grep -qF 'LAUNCH-CHECKLIST.md' "$PROD" || fail "PRODUCTION-READINESS must link LAUNCH-CHECKLIST"
grep -qF 'CONSOLE.md' "$DOC" || fail "LAUNCH-CHECKLIST must link CONSOLE"
for sec in '## A.' '## B.' '## C.' '## D.' '## E.'; do
  grep -qF "$sec" "$DOC" || fail "LAUNCH-CHECKLIST missing section: $sec"
done
grep -qF 'LAUNCH-CHECKLIST.md' "$REPO_ROOT/docs/README.md" \
  || fail "docs/README must link LAUNCH-CHECKLIST"
grep -qF 'CONSOLE.md' "$REPO_ROOT/node/README.md" \
  || fail "node/README must link CONSOLE"
grep -qF 'CONSOLE.md' "$REPO_ROOT/master/README.md" \
  || fail "master/README must link CONSOLE"

echo "verify-launch-checklist-doc: OK"
