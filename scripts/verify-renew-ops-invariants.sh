#!/usr/bin/env bash
# 证书续期 cron 与 ops-webhook 脱敏约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"
CONFIG="${REPO_ROOT}/master/master/config/redi.php"

fail() {
  echo "verify-renew-ops-invariants: $*" >&2
  exit 1
}

renew="${APP}/Console/Commands/RenewDomainCertificatesCommand.php"
cert="${APP}/Models/RediDomainCert.php"
webhook="${APP}/Services/RediOpsWebhook.php"
opsCmd="${APP}/Console/Commands/OpsWebhookNotifyCommand.php"
health="${APP}/Services/RediHealthReport.php"
healthCmd="${APP}/Console/Commands/RediHealthCommand.php"
notifier="${APP}/Services/RediHealthNotifier.php"

for f in "$renew" "$cert" "$webhook" "$opsCmd" "$health" "$healthCmd" "$notifier" "$CONFIG"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'renewalDue' "$renew" || fail "renew command must use renewalDue scope"
grep -q 'renew_validating_stuck_hours' "$renew" || fail "renew must read renew_validating_stuck_hours"
grep -q 'scopeRenewalDue' "$cert" || fail "RediDomainCert must define renewalDue scope"
grep -q 'STATUS_VALIDATING' "$cert" || fail "renewalDue must handle validating stuck"
grep -q 'freshCertWithPersistedRealign' "$renew" || fail "renew must realign cert before queue"
grep -q 'stripControlChars' "$webhook" || fail "webhook must strip control chars"
grep -q 'normalizeWebhookUrl' "$webhook" || fail "webhook must normalize URLs"
grep -q 'notifyTaskFailure' "$opsCmd" || fail "ops-webhook command must delegate to RediOpsWebhook"
grep -q 'cert_validating_stuck' "$health" || fail "health must report validating stuck"
grep -q 'renew_validating_stuck_hours' "$CONFIG" || fail "redi.php must define renew_validating_stuck_hours"
grep -q 'healthWebhookUrl' "$webhook" || fail "RediOpsWebhook must define healthWebhookUrl"
grep -q 'healthWebhookUrl' "$notifier" || fail "RediHealthNotifier must use healthWebhookUrl"
grep -q -- '--notify' "$healthCmd" || fail "redi:health must support --notify"
grep -q 'notifyDelivered' "$healthCmd" || fail "redi:health --notify must track webhook delivery"
grep -q 'notify' "$healthCmd" && grep -q 'notifyDelivered' "$healthCmd" \
  && grep -q 'return ($hasIssues && ! $notifyDelivered)' "$healthCmd" \
  || fail "redi:health --notify must exit 0 when webhook delivered despite open issues"

grep -q 'redi_node_bump_skip' "${REPO_ROOT}/node/redi-multi/api/api.go" \
  || fail "node api must call redi_node_bump_skip procedure"

echo "verify-renew-ops-invariants: OK"
