#!/usr/bin/env bash
# CNAME 预检与 IssueDomainCertificateJob 签发链约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-cname-cert-job-invariants: $*" >&2
  exit 1
}

job="${APP}/Jobs/IssueDomainCertificateJob.php"
cname="${APP}/Services/CnamePrecheckService.php"
guard="${APP}/Services/DomainDnsPointingGuard.php"
issuer="${APP}/Services/Acme/CertificateIssuer.php"

for f in "$job" "$cname" "$guard" "$issuer"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'WithoutOverlapping' "$job" || fail "ACME job must use WithoutOverlapping locks"
grep -q 'redi-acme-le-global' "$job" || fail "ACME job must use global LE lock"
grep -q 'redi-acme-domain-' "$job" || fail "ACME job must use per-domain lock"
grep -q 'pointsToEdge' "$job" || fail "issue job must CNAME precheck"
grep -q 'AcmeChallengeCleanup::purgeForHosts' "$job" || fail "issue failure must purge ACME challenges"
grep -q 'domain_name = ''' "$job" || fail "invalid host must clear domain_name"
grep -q 'isNodePublishableHost' "$cname" || fail "CnamePrecheck must reject unpublishable hosts"
grep -q 'pointsToEdgeCached' "$cname" || fail "CSV/DNS export must use cached precheck"
grep -q 'pointsToTenantEdge' "$guard" || fail "enable guard must use tenant edge precheck"
grep -q 'assertDomainIssuable' "$issuer" || fail "CertificateIssuer must assert issuable"

echo "verify-cname-cert-job-invariants: OK"
