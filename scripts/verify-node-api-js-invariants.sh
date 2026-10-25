#!/usr/bin/env bash
# 节点 /c.js、/api.js 限速与 redirector 出站 URL 约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API="${REPO_ROOT}/node/redi-multi/api/api.go"
ASSET="${REPO_ROOT}/node/redi-multi/api/asset.go"
TEST="${REPO_ROOT}/node/redi-multi/api/api_test.go"

fail() {
  echo "verify-node-api-js-invariants: $*" >&2
  exit 1
}

for f in "$API" "$ASSET" "$TEST"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'rateLimitMiddleware' "$API" || fail "api must rate-limit /c.js"
grep -q 'GET("/api.js", handleApiJs)' "$API" || fail "api.js route must use handleApiJs"
if grep -E 'GET\("/api\.js".*rateLimitMiddleware' "$API" >/dev/null; then
  fail "api.js must not use rateLimitMiddleware (allowApiJsDomainRate already counts global IP limit)"
fi
grep -q 'allowRate' "$API" || fail "missing allowRate"
grep -q 'SanitizeClientIP' "$API" || fail "rate limit must sanitize client IP"
grep -q 'allowApiJsDomainRate' "$API" || fail "api.js must have per-domain rate limit"
grep -q 'apiJsQueryValid' "$API" || fail "api.js query must be validated"
grep -q 'redirecturl.IsAllowed' "$API" || fail "skip_url must pass redirecturl.IsAllowed"
grep -q 'ContainsControlChars' "$API" || fail "REDI_API_LISTEN must reject control chars"
grep -q 'allowRate' "$ASSET" || fail "asset handler must rate limit"
grep -q 'allowApiJsDomainRate' "$ASSET" || fail "WriteAsset /api.js must use allowApiJsDomainRate"
grep -q 'rateLimitMiddleware' "$ASSET" && fail "WriteAsset must not use Echo rateLimitMiddleware"
grep -q 'TestApiJsSourceRejectsControlChars' "$TEST" || fail "api tests must cover control chars in query"

echo "verify-node-api-js-invariants: OK"
