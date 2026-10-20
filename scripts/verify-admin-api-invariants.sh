#!/usr/bin/env bash
# 后台登录与节点 Agent API 限速约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-admin-api-invariants: $*" >&2
  exit 1
}

routes="${REPO_ROOT}/master/master/routes/api.php"
rsp="${APP}/Providers/RouteServiceProvider.php"
auth="${APP}/Admin/Controllers/AuthController.php"
kernel="${APP}/Http/Kernel.php"

for f in "$routes" "$rsp" "$auth" "$kernel"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q "throttle:redi-node" "$routes" || fail "node API must use redi-node throttle"
grep -q "RateLimiter::for('redi-node'" "$rsp" || fail "redi-node rate limiter required"
grep -q 'api/nodes/certificates' "$rsp" || fail "certificates pull must have stricter limit"
grep -q 'containsControlChars' "$rsp" || fail "rate limiter must reject control chars in bearer token"
grep -q 'throttleIpKey' "$rsp" || fail "invalid IP must not poison rate limit keys"
grep -q "throttle:10,1" "$auth" || fail "admin login must be throttled"
grep -q 'throttle:api' "$kernel" || fail "api middleware group must throttle"

echo "verify-admin-api-invariants: OK"
