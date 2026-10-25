#!/usr/bin/env bash
# 证书 last_error 分支判断须走 RediCertLastError::matches，禁止直接比对 last_error 全文。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-redi-cert-error-code-invariants: $*" >&2
  exit 1
}

command -v rg >/dev/null 2>&1 || fail "ripgrep (rg) required"

if rg -n 'last_error\s*===\s*' "$APP" -g '*.php' 2>/dev/null | grep -v 'Support/RediCertLastError.php'; then
  fail 'do not compare cert last_error with ===; use RediCertLastError::matches() or last_error_code'
fi

if rg -n 'DISABLE_DEPLOY_CANCEL_MSG' "$APP" -g '*.php' 2>/dev/null | grep -v 'Models/RediAdminDomain.php'; then
  fail 'use RediCertLastError::DOMAIN_DISABLED_CANCEL instead of DISABLE_DEPLOY_CANCEL_MSG outside the model'
fi

echo "verify-redi-cert-error-code-invariants: ok"
