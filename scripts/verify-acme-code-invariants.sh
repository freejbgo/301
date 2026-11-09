#!/usr/bin/env bash
# 静态校验 ACME 签发链路与主控/节点主机名键约定一致（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"
GO_API="${REPO_ROOT}/node/redi-multi/api/api.go"

fail() {
  echo "verify-acme-code-invariants: $*" >&2
  exit 1
}

issuer="${APP}/Services/Acme/CertificateIssuer.php"
job="${APP}/Jobs/IssueDomainCertificateJob.php"
waiter="${APP}/Services/Acme/Http01PropagationWaiter.php"
challenge="${APP}/Models/RediAcmeChallenge.php"

for f in "$issuer" "$job" "$waiter" "$challenge"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'acmeHostKey' "$issuer" || fail "CertificateIssuer must use acmeHostKey"
grep -q 'originalUrlIfValid' "$job" || fail "IssueDomainCertificateJob must gate on originalUrlIfValid"
grep -q "domain_name = ''" "$job" || fail "IssueDomainCertificateJob must clear domain_name when host invalid"
grep -q 'withoutRedirecting' "$waiter" || fail "Http01PropagationWaiter must not follow redirects on HTTP-01 probe"
grep -q 'acmeHostKey' "$waiter" || fail "Http01PropagationWaiter must normalize domain with acmeHostKey"
grep -q 'subMinutes(15)' "$waiter" || fail "Http01PropagationWaiter must require fresh node heartbeat"
grep -q 'subMinutes(3)' "$waiter" || fail "Http01PropagationWaiter must ignore stale challenge sync rows"

ack="${APP}/Http/Controllers/Api/NodeAgentController.php"
[[ -f "$ack" ]] || fail "missing NodeAgentController"
grep -q 'array_slice($rawDomains, 0, 200)' "$ack" || fail "challenges-synced must cap domain list at 200"
grep -q 'acmeHostKey' "$challenge" || fail "RediAcmeChallenge upsert must use acmeHostKey"

[[ -f "$GO_API" ]] || fail "missing api.go"
grep -q 'AcmeHostKey' "$GO_API" || fail "GetSkipUrl must use AcmeHostKey"

grep -q 'acme_http01_poll_seconds' "${REPO_ROOT}/master/master/config/redi.php" \
  || fail "redi.php must define acme_http01_poll_seconds"

echo "verify-acme-code-invariants: OK"
