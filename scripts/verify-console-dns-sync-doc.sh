#!/usr/bin/env bash
# CONSOLE.md 须包含 DNS API 同步失败运维段落（与 health hint 对齐）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOC="${REPO_ROOT}/docs/CONSOLE.md"

fail() {
  echo "verify-console-dns-sync-doc: $*" >&2
  exit 1
}

[[ -f "$DOC" ]] || fail "missing CONSOLE.md"

grep -q '同步失败后的检查' "$DOC" || fail "CONSOLE must document DNS sync failure checklist"
grep -q 'dns_api_tenants_unsynced' "$DOC" || fail "CONSOLE must mention dns_api_tenants_unsynced hint"
grep -q 'dns_synced_at' "$DOC" || fail "CONSOLE must explain dns_synced_at on partial failure"

echo "verify-console-dns-sync-doc: OK"
