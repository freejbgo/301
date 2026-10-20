#!/usr/bin/env bash
# 证书部署状态机与后台启用守卫关键约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-cert-deploy-invariants: $*" >&2
  exit 1
}

deploy="${APP}/Services/CertificateDeployService.php"
guard="${APP}/Services/DomainDnsPointingGuard.php"
exporter="${APP}/Services/DomainListCsvExporter.php"
domain="${APP}/Models/RediAdminDomain.php"

for f in "$deploy" "$guard" "$exporter" "$domain"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'function reconcileCertDeployStatus' "$deploy" || fail "missing reconcileCertDeployStatus"
grep -q 'certIsNodePullable' "$deploy" || fail "missing certIsNodePullable"
grep -q 'pruneNonPullableDeployments' "$deploy" || fail "missing pruneNonPullableDeployments"
grep -q 'assertCanEnable' "$guard" || fail "DomainDnsPointingGuard must assertCanEnable"
grep -q 'originalUrlIfValid' "$guard" || fail "enable guard must use originalUrlIfValid"
grep -q 'csvSafeCell' "$exporter" || fail "CSV export must sanitize formula cells"
grep -q 'DISABLE_DEPLOY_CANCEL_MSG' "$domain" || fail "domain disable deploy message constant"

echo "verify-cert-deploy-invariants: OK"
