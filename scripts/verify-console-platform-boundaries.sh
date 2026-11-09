#!/usr/bin/env bash
# CONSOLE §8 平台边界与运营约定须与架构/上线清单一致（防文档漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONSOLE="${REPO_ROOT}/docs/CONSOLE.md"
ARCH="${REPO_ROOT}/docs/PLATFORM-ARCHITECTURE.md"
LAUNCH="${REPO_ROOT}/docs/LAUNCH-CHECKLIST.md"

fail() {
  echo "verify-console-platform-boundaries: $*" >&2
  exit 1
}

for f in "$CONSOLE" "$ARCH" "$LAUNCH"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q '## 8. 平台边界与运营约定' "$CONSOLE" || fail "CONSOLE must have §8 platform boundaries"
grep -qF 'PLATFORM-ARCHITECTURE.md' "$CONSOLE" || fail "CONSOLE §8 must link PLATFORM-ARCHITECTURE"
grep -qF 'NS 委托' "$CONSOLE" || fail "CONSOLE §8 must document no NS delegation"
grep -qF '多 pool' "$CONSOLE" || fail "CONSOLE §8 must document single pool model"
grep -qF '逐域 TXT' "$CONSOLE" || fail "CONSOLE §8 must document no per-domain TXT primary flow"
grep -qF 'HTTP 200 + JS' "$CONSOLE" || fail "CONSOLE §8 must document 200+JS redirect model"
grep -qF 'QUEUE_CONNECTION' "$CONSOLE" || fail "CONSOLE §8 must document queue driver"

grep -qF 'HTTP-01' "$ARCH" || fail "PLATFORM-ARCHITECTURE must document HTTP-01"
grep -qF 'CNAME' "$ARCH" || fail "PLATFORM-ARCHITECTURE must document CNAME model"

grep -qF 'CONSOLE.md' "$LAUNCH" || fail "LAUNCH-CHECKLIST must reference CONSOLE"
grep -qF '§8' "$LAUNCH" || fail "LAUNCH-CHECKLIST must reference CONSOLE §8"
grep -qE 'HTTP 200 \+ JS|200 \+ JS' "$LAUNCH" || fail "LAUNCH-CHECKLIST must mention 200+JS acceptance"

echo "verify-console-platform-boundaries: OK"
