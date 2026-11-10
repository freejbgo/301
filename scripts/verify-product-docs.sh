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
  docs/I18N.md \
  docs/HEALTH-REFERENCE.md \
  docs/PLATFORM-ARCHITECTURE.md \
  docs/NETWORKING-IPv6.md \
  docs/PRODUCTION-READINESS.md \
  docs/LAUNCH-CHECKLIST.md \
  docs/RELEASE-PACKAGES.md \
  docs/DEPLOY-TUTORIAL-CENTOS7.md \
  docs/OPS-RELEASE-UPDATE.md \
  docs/GREENFIELD-PRODUCT.md \
  docs/ACCEPTANCE.md; do
  [[ -f "$REPO_ROOT/$f" ]] || fail "missing $f"
done

[[ ! -f "$REPO_ROOT/docs/UPGRADE-IN-PLACE.md" ]] || fail 'rename UPGRADE-IN-PLACE.md to docs/OPS-RELEASE-UPDATE.md'

for gone in \
  docs/AUDIT-CENTOS7-PRODUCTION.md \
  docs/BATCH-OPERATIONS-REPORT.md \
  docs/DEVELOPMENT-EDGE-POOL-HTTPS.md \
  docs/IPV6-DUALSTACK-DEV.md \
  docs/IPV6-DUALSTACK-IMPLEMENTATION.md \
  docs/I18N-DEVELOPMENT-REPORT.md \
  docs/ROADMAP-PRODUCTION.md; do
  [[ ! -f "$REPO_ROOT/$gone" ]] || fail "removed doc still present: $gone"
done

if grep -rE 'AUDIT-CENTOS7-PRODUCTION|DEVELOPMENT-EDGE-POOL-HTTPS|IPV6-DUALSTACK-(DEV|IMPLEMENTATION)|I18N-DEVELOPMENT-REPORT|ROADMAP-PRODUCTION' \
  --include='*.md' --include='*.sh' --include='.env.example' "$REPO_ROOT" \
  --exclude='verify-product-docs.sh' \
  | grep -v '^Binary' | grep -q .; then
  grep -rE 'AUDIT-CENTOS7-PRODUCTION|DEVELOPMENT-EDGE-POOL-HTTPS|IPV6-DUALSTACK-(DEV|IMPLEMENTATION)|I18N-DEVELOPMENT-REPORT|ROADMAP-PRODUCTION' \
    --include='*.md' --include='*.sh' --include='.env.example' "$REPO_ROOT" \
    --exclude='verify-product-docs.sh' || true
  fail 'stale references to removed docs'
fi

grep -qF 'docs/README.md' "$REPO_ROOT/README.md" || fail 'README must link docs/README.md'
grep -qF 'GREENFIELD-PRODUCT.md' "$REPO_ROOT/README.md" || fail 'README must link GREENFIELD-PRODUCT.md'
grep -qF 'GREENFIELD-PRODUCT.md' "$REPO_ROOT/docs/README.md" || fail 'docs/README must link GREENFIELD-PRODUCT'
grep -qF '按角色怎么读' "$REPO_ROOT/docs/GREENFIELD-PRODUCT.md" \
  || fail 'GREENFIELD-PRODUCT must document role-based reading order'
grep -qF 'RELEASE-PACKAGES.md' "$REPO_ROOT/docs/DEVELOPMENT.md" \
  || fail 'DEVELOPMENT must link RELEASE-PACKAGES'
grep -qF 'build-release-tarballs.sh' "$REPO_ROOT/docs/RELEASE-PACKAGES.md" \
  || fail 'RELEASE-PACKAGES must document local build-release-tarballs.sh'
grep -qF 'DEPLOY-TUTORIAL-CENTOS7.md' "$REPO_ROOT/DEPLOY-centos7.md" \
  || fail 'DEPLOY-centos7 must link DEPLOY-TUTORIAL-CENTOS7'
grep -qF 'deploy-master.sh' "$REPO_ROOT/docs/DEPLOY-TUTORIAL-CENTOS7.md" \
  || fail 'DEPLOY-TUTORIAL must document deploy-master.sh'
grep -qF 'CONSOLE.md' "$REPO_ROOT/docs/README.md" || fail 'docs/README must link CONSOLE'
grep -qF '## 8. 平台边界与运营约定' "$REPO_ROOT/docs/CONSOLE.md" \
  || fail 'CONSOLE must document platform boundaries (§8)'
grep -qF '## 已知限制与计划增强' "$REPO_ROOT/docs/PRODUCTION-READINESS.md" \
  || fail 'PRODUCTION-READINESS must document known limits'
grep -qF 'I18N.md' "$REPO_ROOT/docs/README.md" || fail 'docs/README must link I18N.md'
grep -qF 'OPS-RELEASE-UPDATE.md' "$REPO_ROOT/docs/README.md" \
  || fail 'docs/README must link OPS-RELEASE-UPDATE.md'
grep -qF 'PLATFORM-ARCHITECTURE.md' "$REPO_ROOT/docs/ACCEPTANCE.md" \
  || fail 'ACCEPTANCE.md must link PLATFORM-ARCHITECTURE'
[[ ! -f "$REPO_ROOT/docs/ACCEPTANCE-CENTOS7-DEPLOY.md" ]] \
  || fail 'removed ACCEPTANCE-CENTOS7-DEPLOY.md still present'
[[ ! -f "$REPO_ROOT/docs/ACCEPTANCE-EDGE-POOL.md" ]] \
  || fail 'removed ACCEPTANCE-EDGE-POOL.md still present'
[[ ! -f "$REPO_ROOT/DEPLOY-centos7-example.md" ]] \
  || fail 'removed DEPLOY-centos7-example.md still present'

if grep -qE 'V6-[0-9]{2}|## 3\. 陷阱' "$REPO_ROOT/docs/NETWORKING-IPv6.md"; then
  fail 'NETWORKING-IPv6.md should stay operator-focused (no V6-xx dev ledger)'
fi

echo "verify-product-docs: OK"
