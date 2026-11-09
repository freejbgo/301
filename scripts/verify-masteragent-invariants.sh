#!/usr/bin/env bash
# Go masteragent：证书拉取、ack 与注册状态约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AGENT="${REPO_ROOT}/node/redi-multi/masteragent/agent.go"
REG="${REPO_ROOT}/node/redi-multi/masteragent/registration_test.go"
ACK_ERR="${REPO_ROOT}/node/redi-multi/masteragent/ack_error.go"

fail() {
  echo "verify-masteragent-invariants: $*" >&2
  exit 1
}

for f in "$AGENT" "$REG" "$ACK_ERR"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'host.AcmeHostKey(row.Domain)' "$AGENT" || fail "pullCertificates must normalize domain with AcmeHostKey"
grep -q 'host.ContainsControlChars(row.Domain)' "$AGENT" || fail "pullCertificates must skip control chars in domain"
grep -q 'tls.X509KeyPair' "$AGENT" || fail "pullCertificates must validate PEM before install"
grep -q '"/api/nodes/ack"' "$AGENT" || fail "pullCertificates must POST ack"
grep -q 'sanitizeAckError' "$AGENT" || fail "failed ack must sanitize error"
grep -q 'certs.ReplaceAll(nil)' "$AGENT" || fail "empty cert list must clear in-memory certs"
grep -q 'noteEmptyCertificateList' "$AGENT" || fail "empty cert list must debounce before ReplaceAll(nil)"
grep -q 'noteEmptyChallengeList' "$AGENT" || fail "empty acme-challenges list must debounce before ReplaceAll"
grep -q 'noteEmptyRedirectRulesList' "$AGENT" || fail "empty redirect-rules list must debounce before ReplaceForceHTTPS"
grep -q 'host.ContainsControlChars(r.OriginalURL)' "$AGENT" || fail "pullRedirectRules must skip control chars in host"
grep -q 'host.AcmeHostKey(r.OriginalURL)' "$AGENT" || fail "pullRedirectRules must normalize host with AcmeHostKey"
grep -q 'rules.ReplaceForceHTTPS' "$AGENT" || fail "pullRedirectRules must call rules.ReplaceForceHTTPS"
grep -q 'nextPollIntervalAfterError' "${REPO_ROOT}/node/redi-multi/masteragent/backoff.go" || fail "masteragent must backoff on tick errors"
grep -q 'warnThrottled' "${REPO_ROOT}/node/redi-multi/masteragent/log_throttle.go" || fail "masteragent must throttle pull warnings"
grep -q 'warnThrottled("pull-certificates"' "$AGENT" || fail "pull certificates must use warnThrottled"
grep -q 'shouldClearRegistration' "$AGENT" || fail "missing shouldClearRegistration"
grep -q 'ack 403 must not clear registration' "$REG" || fail "registration_test must guard ack 403"
grep -q 'ack 409 must not clear registration' "$REG" || fail "registration_test must guard ack 409"
grep -q 'revs.legacy' "$AGENT" || fail "tick must track legacy combined revision"
grep -q 'rev.Revision != "" && rev.Revision != revs.legacy' "$AGENT" \
  || fail "tick must pull all feeds when only combined revision changes"

TICK_TEST="${REPO_ROOT}/node/redi-multi/masteragent/tick_test.go"
[[ -f "$TICK_TEST" ]] || fail "missing tick_test.go"
grep -q 'TestTickLegacyCombinedRevision' "$TICK_TEST" || fail "tick_test must cover legacy combined revision"

EMPTY_RULES="${REPO_ROOT}/node/redi-multi/masteragent/empty_rules_test.go"
[[ -f "$EMPTY_RULES" ]] || fail "missing empty_rules_test.go"
grep -q 'TestEmptyRedirectRulesListDebouncesBeforeClear' "$EMPTY_RULES" \
  || fail "empty_rules_test must debounce before clearing force_https map"

echo "verify-masteragent-invariants: OK"
