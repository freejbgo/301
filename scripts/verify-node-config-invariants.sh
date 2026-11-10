#!/usr/bin/env bash
# 节点 config.json 约定：redirector 不参与运行，跳转来自 MySQL。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${REPO_ROOT}/node/redi-multi/config/config.go"
EXAMPLE="${REPO_ROOT}/node/redi-multi/config.json.example"
NODE_README="${REPO_ROOT}/node/README.md"

fail() {
  echo "verify-node-config-invariants: $*" >&2
  exit 1
}

[[ -f "$CFG" ]] || fail "missing config.go"
[[ -f "$EXAMPLE" ]] || fail "missing config.json.example"
[[ -f "$NODE_README" ]] || fail "missing node/README.md"

grep -q 'redirector JSON is ignored' "$CFG" || fail "config loader must ignore redirector field"
grep -q 'jsonStringMapOptional.*redirector' "$CFG" || fail "config must read redirector only to detect presence"
grep -qF 'redirector' "$NODE_README" || fail "node README must document redirector is unused"
grep -qF 'default_redirect_url' "$EXAMPLE" || fail "config.json.example must set default_redirect_url"
if grep -q '"redirector"' "$EXAMPLE"; then
  fail "config.json.example must not ship redirector (MySQL rules only)"
fi

echo "verify-node-config-invariants: OK"
