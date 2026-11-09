#!/usr/bin/env bash
# redi:health / 租户首页 / 批量域名：跨租户与展示安全约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-health-tenant-invariants: $*" >&2
  exit 1
}

home="${APP}/Admin/Controllers/HomeController.php"
health="${APP}/Services/RediHealthReport.php"
tenant="${APP}/Services/TenantOpsSummary.php"
batch="${APP}/Admin/Forms/BatchCreateDomains.php"
batchEdit="${APP}/Admin/Forms/BatchEditDomains.php"
batchSvc="${APP}/Services/DomainBatchCreator.php"
batchMut="${APP}/Services/DomainBatchMutator.php"
batchFilter="${APP}/Services/DomainBatchFilterRunner.php"
urlGroup="${APP}/Admin/Forms/UrlSetByGroup.php"
sanitizer="${APP}/Support/OpsDisplaySanitizer.php"

for f in "$home" "$health" "$tenant" "$batch" "$batchEdit" "$batchSvc" "$batchMut" "$batchFilter" "$urlGroup" "$sanitizer"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'isAdministrator' "$home" || fail "HomeController must gate platform health dashboard"
grep -q 'TenantOpsSummary::forAdmin' "$home" || fail "tenant home must use TenantOpsSummary"
grep -q 'sanitizeHealthIssues' "$health" || fail "RediHealthReport must sanitize health messages"
grep -q 'dns_api_tenants_unsynced' "$health" || fail "RediHealthReport must hint dns_api_tenants_unsynced"
grep -q "where('admin_id', \$adminId)" "$tenant" || fail "TenantOpsSummary must scope by admin_id"
grep -q 'isPlatformAdministrator' "$batch" || fail "BatchCreateDomains must check platform vs tenant"
grep -q "where('admin_id', \$adminId)" "$batchSvc" || fail "DomainBatchCreator must verify group admin_id"
grep -q 'MAX_LINES_PER_BATCH' "$batchSvc" || fail "DomainBatchCreator batch limit"
grep -q 'applyEditsOwned' "$batchEdit" || fail "BatchEditDomains must use DomainBatchMutator::applyEditsOwned"
grep -q 'normalizeOwnedIds' "$batchMut" || fail "DomainBatchMutator must normalize owned domain ids"
grep -q "where('admin_id', \$sessionAdminId)" "$batchMut" || fail "DomainBatchMutator must scope domains by admin_id"
grep -q 'DomainListFilter::hasAnyFilter' "$batchFilter" || fail "DomainBatchFilterRunner must require list filters for sensitive ops"
grep -q 'filter_required_sensitive' "$batchFilter" || fail "DomainBatchFilterRunner must gate sensitive batch without filter"
grep -q 'isPlatformAdministrator' "$urlGroup" || fail "UrlSetByGroup must check tenant ownership"
grep -q 'sanitizeHealthIssues' "$sanitizer" || fail "OpsDisplaySanitizer health helper"

echo "verify-health-tenant-invariants: OK"
