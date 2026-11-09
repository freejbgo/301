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
grep -q "csvSafeCell(\$cert" "$exporter" || fail "CSV export must sanitize cert_status column"
grep -q 'RediCertLastError::DOMAIN_DISABLED_CANCEL' "$domain" || fail "domain disable must use RediCertLastError::DOMAIN_DISABLED_CANCEL"
grep -q 'function markNodeAck' "$deploy" || fail "missing markNodeAck"
grep -q 'refreshCertStatusFromDeployments' "$deploy" || fail "missing refreshCertStatusFromDeployments"
grep -q 'handleDomainReenabledFromDisabled' "$deploy" || fail "missing handleDomainReenabledFromDisabled"
grep -q 'handleNodeDisabled' "$deploy" || fail "missing handleNodeDisabled"

ack="${APP}/Http/Controllers/Api/NodeAgentController.php"
queue="${APP}/Services/DomainCertificateQueue.php"
redeploy="${APP}/Services/DomainCertificateRedeployQueue.php"
[[ -f "$ack" ]] || fail "missing NodeAgentController"
[[ -f "$queue" ]] || fail "missing DomainCertificateQueue"
[[ -f "$redeploy" ]] || fail "missing DomainCertificateRedeployQueue"

grep -q 'certIsNodePullable' "$ack" || fail "certificates pull must use certIsNodePullable"
grep -q "where('status', 1)" "$ack" || fail "certificates and redirect-rules must only expose enabled domains"
grep -q 'acmeHostKey' "$ack" || fail "certificates pull must normalize domain with acmeHostKey"
grep -q 'ack not applied' "$ack" || fail "ack must return 409 when not applied"
grep -q 'containsControlChars' "$ack" || fail "ack must reject control chars in error"
grep -q 'ignored' "$ack" || fail "ack must ignore missing deployment row"
grep -q "where('status', 1)" "$queue" || fail "batch apply queue must only enqueue enabled domains"
grep -q 'certIsEligibleForDeployQueue' "$redeploy" || fail "redeploy must check eligible certs"

echo "verify-cert-deploy-invariants: OK"
