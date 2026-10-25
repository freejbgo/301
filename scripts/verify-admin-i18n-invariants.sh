#!/usr/bin/env bash
# 后台可见界面 i18n 约定（防重构回退硬编码中文）：Admin 控制器、模型校验、config 回退。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ADMIN_APP="${REPO_ROOT}/master/master/app/Admin"
ADMIN_MODELS="${REPO_ROOT}/master/master/app/Models"
ADMIN_CFG="${REPO_ROOT}/master/master/config/admin.php"
LOCALE_MW="${REPO_ROOT}/master/master/app/Http/Middleware/SetAdminLocale.php"
PARITY_TEST="${REPO_ROOT}/master/master/tests/Unit/RediLangParityTest.php"
EVENTS="${REPO_ROOT}/master/master/app/Providers/EventServiceProvider.php"
APP_PROVIDER="${REPO_ROOT}/master/master/app/Providers/AppServiceProvider.php"
MENU_PARTIAL="${REPO_ROOT}/master/master/resources/views/admin/partials/menu.blade.php"
LOCALE_SWITCHER_DIR="${REPO_ROOT}/master/master/resources/views/admin/partials"

fail() {
  echo "verify-admin-i18n-invariants: $*" >&2
  exit 1
}

command -v rg >/dev/null 2>&1 || fail "ripgrep (rg) required"

for f in "$ADMIN_CFG" "$LOCALE_MW" "$PARITY_TEST" "$EVENTS" "$APP_PROVIDER" "$MENU_PARTIAL"; do
  [[ -f "$f" ]] || fail "missing $f"
done

middleware_block="$(awk "/        'middleware' => \[/,/        \],/" "$ADMIN_CFG" | head -n -1)"
echo "$middleware_block" | grep -q 'SetAdminLocale' || fail 'route middleware must include SetAdminLocale'
locale_pos="$(echo "$middleware_block" | grep -n 'SetAdminLocale' | head -1 | cut -d: -f1)"
admin_pos="$(echo "$middleware_block" | grep -n "            'admin'," | head -1 | cut -d: -f1)"
if [[ -z "$locale_pos" || -z "$admin_pos" || "$locale_pos" -ge "$admin_pos" ]]; then
  fail 'SetAdminLocale must be registered before admin middleware'
fi

grep -q 'SyncAdminLocaleOnLogin' "$EVENTS" || fail 'Login listener SyncAdminLocaleOnLogin must be registered'

grep -q 'LocalizedMenu' "$APP_PROVIDER" || fail 'AppServiceProvider must register LocalizedMenu as admin.menu'
grep -q 'translateMenuItem' "$MENU_PARTIAL" || fail 'admin menu partial must use translateMenuItem'

for switcher in "$LOCALE_SWITCHER_DIR"/locale-switcher*.blade.php; do
  [[ -f "$switcher" ]] || continue
  if rg -n 'redi-locale\.names' "$switcher" >/dev/null; then
    continue
  fi
  fail "locale switcher must use redi-locale.names: $switcher"
done

if rg -n "('|\")([^'\"\\]|\\.)*[\x{4e00}-\x{9fff}]" "$ADMIN_APP" -g '*.php' 2>/dev/null; then
  fail 'app/Admin must not contain Han characters inside PHP string literals (use language files)'
fi

if [[ ! -d "$ADMIN_MODELS" ]]; then
  fail "missing $ADMIN_MODELS"
fi
if rg -n "('|\")([^'\"\\]|\\.)*[\x{4e00}-\x{9fff}]" "$ADMIN_MODELS" -g '*.php' 2>/dev/null; then
  fail 'app/Models must not contain Han in string literals (use redi-admin-* / __() for validation messages)'
fi

if rg -n "('|\")([^'\"\\]|\\.)*[\x{4e00}-\x{9fff}]" "$ADMIN_CFG" 2>/dev/null; then
  fail 'config/admin.php must not contain Han in string literals (use English file fallback; PlatformBranding applies per locale)'
fi

ADMIN_FACING=(
  "${REPO_ROOT}/master/master/app/Services/DomainBatchCreator.php"
  "${REPO_ROOT}/master/master/app/Services/DomainDnsPointingGuard.php"
  "${REPO_ROOT}/master/master/app/Models/RediAdminDomain.php"
)
for f in "${ADMIN_FACING[@]}"; do
  [[ -f "$f" ]] || fail "missing $f"
  if rg -n 'RediErrorMessage' "$f" >/dev/null 2>&1; then
    fail "$(basename "$f") must use __() / redi-admin-* / redi-batch-domains, not RediErrorMessage (ops English)"
  fi
done

echo "verify-admin-i18n-invariants: ok"
