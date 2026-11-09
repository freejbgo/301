#!/usr/bin/env bash
# 80/443 accept 循环须在 goroutine 前预占连接槽（防连接风暴占满 goroutine）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HTTP="${REPO_ROOT}/node/redi-multi/http/http.go"
HTTPS="${REPO_ROOT}/node/redi-multi/https/https.go"
LIMIT="${REPO_ROOT}/node/redi-multi/redirect/limit.go"

fail() {
  echo "verify-redirect-conn-limit: $*" >&2
  exit 1
}

for f in "$HTTP" "$HTTPS" "$LIMIT"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'func TryAcquireConnection' "$LIMIT" || fail "redirect must export TryAcquireConnection"
grep -q 'TryAcquireConnection' "$HTTP" || fail "http accept must pre-acquire connection slot"
grep -q 'ProcessWithAcquiredSlot' "$HTTP" || fail "http must use ProcessWithAcquiredSlot"
grep -q 'TryAcquireConnection' "$HTTPS" || fail "https accept must pre-acquire connection slot"
grep -q 'ProcessWithAcquiredSlot' "$HTTPS" || fail "https must use ProcessWithAcquiredSlot"
grep -q 'SO_RCVBUF' "$HTTP" || fail "http listener must set SO_RCVBUF (lagran)"
grep -q 'SO_RCVBUF' "$HTTPS" || fail "https listener must set SO_RCVBUF (lagran)"

echo "verify-redirect-conn-limit: OK"
