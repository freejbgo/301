#!/usr/bin/env bash
# ACME 挑战清理、prune cron 与 failed 部署重排队约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-acme-prune-requeue-invariants: $*" >&2
  exit 1
}

prune="${APP}/Console/Commands/PruneExpiredAcmeChallenges.php"
cleanup="${APP}/Services/AcmeChallengeCleanup.php"
query="${APP}/Services/AcmeChallengeQuery.php"
requeue="${APP}/Console/Commands/RequeueFailedDeploymentsCommand.php"
deploy="${APP}/Services/CertificateDeployService.php"
ops="${APP}/Admin/Controllers/OpsController.php"
domain="${APP}/Models/RediAdminDomain.php"

for f in "$prune" "$cleanup" "$query" "$requeue" "$deploy" "$ops" "$domain"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'AcmeChallengeCleanup::purgeForHosts' "$prune" || fail "prune must purge node sync via AcmeChallengeCleanup"
grep -q 'nodePublishableRow' "$prune" || fail "prune must drop malformed challenges"
grep -q 'canonicalPurgeHost' "$cleanup" || fail "AcmeChallengeCleanup must canonicalize hosts"
grep -q 'acmeHostKey' "$cleanup" || fail "purge must use acmeHostKey"
grep -q 'containsControlChars' "$cleanup" || fail "purge must reject control chars in input"
grep -q 'requeueFailedDeployments' "$requeue" || fail "requeue command must call deploy service"
grep -q 'certIsEligibleForDeployQueue' "$deploy" || fail "requeue must filter eligible certs"
grep -q 'pruneNonPullableDeployments' "$deploy" || fail "requeue must prune non-pullable failed rows"
grep -q 'EnsuresAdministrator' "$ops" || fail "ops requeue must be administrator-only"
grep -q 'SafeRelativeRedirect' "$ops" || fail "ops requeue redirect must be safe"
grep -q 'AcmeChallengeCleanup::purgeForHosts' "$domain" || fail "domain disable/delete must purge ACME"

echo "verify-acme-prune-requeue-invariants: OK"
