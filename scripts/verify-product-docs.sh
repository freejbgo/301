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
  docs/README.md \
  docs/DEVELOPMENT.md \
  docs/HEALTH-REFERENCE.md \
  docs/PLATFORM-ARCHITECTURE.md \
  docs/NETWORKING-IPv6.md \
  docs/PRODUCTION-READINESS.md \
  docs/LAUNCH-CHECKLIST.md \
  docs/RELEASE-PACKAGES.md; do
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

grep -qF 'docs/README.md' "$REPO_ROOT/README.md" || fail 'README must link docs/README.md'
grep -qF 'RELEASE-PACKAGES.md' "$REPO_ROOT/docs/DEVELOPMENT.md" \
  || fail 'DEVELOPMENT must link RELEASE-PACKAGES'
grep -qF 'CONSOLE.md' "$REPO_ROOT/docs/README.md" || fail 'docs/README must link CONSOLE'
grep -qF '## 8. 平台边界与运营约定' "$REPO_ROOT/docs/CONSOLE.md" \
  || fail 'CONSOLE must document platform boundaries (§8)'
grep -qE '## 计划中的增强' "$REPO_ROOT/docs/ROADMAP-PRODUCTION.md" \
  || fail 'ROADMAP must use future-work sections only'
if grep -q '## 已具备' "$REPO_ROOT/docs/ROADMAP-PRODUCTION.md"; then
  fail 'ROADMAP must not duplicate shipped capability ledger'
fi
grep -qF 'PLATFORM-ARCHITECTURE.md' "$REPO_ROOT/docs/ACCEPTANCE-EDGE-POOL.md" \
  || fail 'ACCEPTANCE-EDGE-POOL must link PLATFORM-ARCHITECTURE'

if grep -qE 'V6-[0-9]{2}|## 3\. 陷阱' "$REPO_ROOT/docs/NETWORKING-IPv6.md"; then
  fail 'NETWORKING-IPv6.md should stay operator-focused (no V6-xx dev ledger)'
fi

echo "verify-product-docs: OK"
