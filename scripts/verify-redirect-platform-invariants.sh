#!/usr/bin/env bash
# 跳转 URL（PHP/Go 对齐）、force HTTPS 规则与平台 DNS 表单约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-redirect-platform-invariants: $*" >&2
  exit 1
}

normalizer="${APP}/Services/DomainRecordNormalizer.php"
settings="${APP}/Admin/Forms/PlatformDnsSettings.php"
platform="${APP}/Models/RediPlatformDns.php"
redirect="${REPO_ROOT}/node/redi-multi/util/redirecturl/redirecturl.go"
redirectTest="${REPO_ROOT}/node/redi-multi/util/redirecturl/redirecturl_test.go"
rules="${REPO_ROOT}/node/redi-multi/rules/store.go"
agent="${REPO_ROOT}/node/redi-multi/masteragent/agent.go"

for f in "$normalizer" "$settings" "$platform" "$redirect" "$redirectTest" "$rules" "$agent"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'isAllowedRedirectUrl' "$normalizer" || fail "DomainRecordNormalizer must gate skip_url"
grep -q 'normalizeSkipUrl' "$normalizer" || fail "missing normalizeSkipUrl"
grep -q 'func IsAllowed' "$redirect" || fail "redirecturl.IsAllowed required"
grep -q 'u.User != nil' "$redirect" || fail "redirecturl must reject userinfo"
grep -q 'javascript' "$redirectTest" || fail "redirecturl tests must reject javascript"
grep -q 'AcmeHostKey' "$rules" || fail "force HTTPS rules must use AcmeHostKey"
grep -q 'ReplaceForceHTTPS' "$agent" || fail "masteragent must sync force_https rules"
grep -q 'invalidateAllDnsSyncedAt' "$settings" || fail "pool_host change must invalidate dns_synced_at"
grep -q 'rebuildAllEdgeFqdn' "$settings" || fail "edge_zone change must rebuild tenant edges"
grep -q 'isSupportedApiDriver' "$settings" || fail "platform DNS must validate API driver"
grep -q 'poolFqdn' "$platform" || fail "RediPlatformDns must expose poolFqdn"

echo "verify-redirect-platform-invariants: OK"
