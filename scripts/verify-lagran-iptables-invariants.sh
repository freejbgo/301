#!/usr/bin/env bash
# lagran iptables 安装返回值与 IPv4/IPv6 分工（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LAGRAN="${REPO_ROOT}/node/redi-multi/lagran/lagran.go"
RULES="${REPO_ROOT}/node/redi-multi/lagran/iptables_rules.go"

fail() {
  echo "verify-lagran-iptables-invariants: $*" >&2
  exit 1
}

[[ -f "$LAGRAN" ]] || fail "missing lagran.go"
[[ -f "$RULES" ]] || fail "missing iptables_rules.go"

grep -q 'func appendLagranRules.*bool' "$RULES" || fail "appendLagranRules must return bool"
grep -q 'return v4ok' "$LAGRAN" || fail "setIptable must return IPv4 rule install result"
grep -q 'ip6tables new error' "$LAGRAN" || fail "setIptable must warn on ip6tables failure without failing v4"

echo "verify-lagran-iptables-invariants: OK"
