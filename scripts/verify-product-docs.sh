#!/usr/bin/env bash
# 仓库文档以「当前产品」为准：无 AUDIT/开发进度类主文档，核心链可解析。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

fail() {
  echo "verify-product-docs: $*" >&2
  exit 1
}

for f in \
  docs/DEVELOPMENT.md \
  docs/HEALTH-REFERENCE.md \
  docs/PLATFORM-ARCHITECTURE.md \
  docs/NETWORKING-IPv6.md \
  docs/PRODUCTION-READINESS.md; do
  [[ -f "$REPO_ROOT/$f" ]] || fail "missing $f"
done

for gone in \
  docs/AUDIT-CENTOS7-PRODUCTION.md \
  docs/DEVELOPMENT-EDGE-POOL-HTTPS.md \
  docs/IPV6-DUALSTACK-DEV.md \
  docs/IPV6-DUALSTACK-IMPLEMENTATION.md; do
  [[ ! -f "$REPO_ROOT/$gone" ]] || fail "removed doc still present: $gone"
done

if grep -rE 'AUDIT-CENTOS7-PRODUCTION|DEVELOPMENT-EDGE-POOL-HTTPS|IPV6-DUALSTACK-(DEV|IMPLEMENTATION)' \
  --include='*.md' --include='*.sh' --include='.env.example' "$REPO_ROOT" \
  --exclude='verify-product-docs.sh' \
  | grep -v '^Binary' | grep -q .; then
  grep -rE 'AUDIT-CENTOS7-PRODUCTION|DEVELOPMENT-EDGE-POOL-HTTPS|IPV6-DUALSTACK-(DEV|IMPLEMENTATION)' \
    --include='*.md' --include='*.sh' --include='.env.example' "$REPO_ROOT" \
    --exclude='verify-product-docs.sh' || true
  fail 'stale references to removed docs'
fi

grep -qF 'docs/DEVELOPMENT.md' "$REPO_ROOT/README.md" || fail 'README must link DEVELOPMENT.md'
grep -qF 'PLATFORM-ARCHITECTURE.md' "$REPO_ROOT/docs/ACCEPTANCE-EDGE-POOL.md" \
  || fail 'ACCEPTANCE-EDGE-POOL must link PLATFORM-ARCHITECTURE'

echo "verify-product-docs: OK"
