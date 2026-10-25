#!/usr/bin/env bash
# 运维 / 节点 API 层：禁止在 PHP 字符串字面量中硬编码中文（应走 en/redi-errors 或英文固定文案）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ROOT="${REPO_ROOT}/master/master/app"
OPS_DIRS=(
  "${APP_ROOT}/Console"
  "${APP_ROOT}/Support"
  "${APP_ROOT}/Http"
  "${APP_ROOT}/Jobs"
)
SERVICES_DIR="${APP_ROOT}/Services"

fail() {
  echo "verify-ops-english-invariants: $*" >&2
  exit 1
}

command -v rg >/dev/null 2>&1 || fail "ripgrep (rg) required"

han_pattern="('|\")([^'\"\\]|\\.)*[\x{4e00}-\x{9fff}]"

for dir in "${OPS_DIRS[@]}"; do
  [[ -d "$dir" ]] || fail "missing $dir"
  if rg -n "$han_pattern" "$dir" -g '*.php' 2>/dev/null; then
    fail "$(basename "$dir") must not contain Han in PHP string literals (use RediErrorMessage / English)"
  fi
done

[[ -d "$SERVICES_DIR" ]] || fail "missing $SERVICES_DIR"
service_hits="$(rg -n "$han_pattern" "$SERVICES_DIR" -g '*.php' 2>/dev/null || true)"
if [[ -n "$service_hits" ]]; then
  # DNSPod API default record line name (vendor parameter, not user-facing copy).
  remaining="$(echo "$service_hits" | rg -v 'DnspodDnsProvider\.php' || true)"
  if [[ -n "$remaining" ]]; then
    echo "$remaining" >&2
    fail 'app/Services must not contain Han in PHP string literals (except documented DNSPod record line default)'
  fi
  if ! echo "$service_hits" | rg -q 'DnspodDnsProvider\.php'; then
    echo "$service_hits" >&2
    fail 'unexpected Han in app/Services'
  fi
fi

echo "verify-ops-english-invariants: ok"
