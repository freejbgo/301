#!/usr/bin/env bash
# 节点数据面 skip_url / bump_skip 与主机名键约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API="${REPO_ROOT}/node/redi-multi/api/api.go"
TEST="${REPO_ROOT}/node/redi-multi/api/api_test.go"
PROC="${REPO_ROOT}/scripts/verify-redi-node-procedure-sync.php"

fail() {
  echo "verify-node-skip-api-invariants: $*" >&2
  exit 1
}

for f in "$API" "$TEST" "$PROC"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'func GetSkipUrl' "$API" || fail "missing GetSkipUrl"
grep -q 'host.AcmeHostKey' "$API" || fail "GetSkipUrl must use AcmeHostKey"
grep -q 'redi_node_bump_skip' "$API" || fail "GetSkipUrl must CALL redi_node_bump_skip"
grep -q 'mysqlconnect.Reconnect' "$API" || fail "GetSkipUrl must reconnect on DB error"
grep -q 'TestGetSkipUrlRejectsInvalidHostWithoutDb' "$TEST" || fail "api tests must cover invalid hosts"
grep -q 'redi_node_bump_skip' "$PROC" || fail "procedure sync must cover bump_skip"

echo "verify-node-skip-api-invariants: OK"
