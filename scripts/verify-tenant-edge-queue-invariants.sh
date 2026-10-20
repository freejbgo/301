#!/usr/bin/env bash
# 租户 edge / CNAME 预检 / ACME 队列锁约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${REPO_ROOT}/master/master/app"

fail() {
  echo "verify-tenant-edge-queue-invariants: $*" >&2
  exit 1
}

tenant="${APP}/Services/TenantEdgeService.php"
settings="${APP}/Admin/Forms/PlatformDnsSettings.php"
job="${APP}/Jobs/IssueDomainCertificateJob.php"
guard="${APP}/Services/DomainDnsPointingGuard.php"
cname="${APP}/Services/CnamePrecheckService.php"
queue="${APP}/Services/DomainCertificateQueue.php"
redeploy="${APP}/Services/DomainCertificateRedeployQueue.php"
exporter="${APP}/Services/DomainListCsvExporter.php"
tenantTrait="${APP}/Models/Concerns/BelongsToAdminTenant.php"

for f in "$tenant" "$settings" "$job" "$guard" "$cname" "$queue" "$redeploy" "$exporter" "$tenantTrait"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q 'invalidateAllDnsSyncedAt' "$tenant" || fail "TenantEdgeService must invalidate dns_synced_at"
grep -q 'invalidateAllDnsSyncedAt' "$settings" || fail "PlatformDnsSettings must invalidate on pool_host change"
grep -q 'redi-acme-le-global' "$job" || fail "IssueDomainCertificateJob must use global ACME lock"
grep -q 'redi-acme-domain-' "$job" || fail "IssueDomainCertificateJob must use per-domain lock"
grep -q 'originalUrlIfValid' "$guard" || fail "DomainDnsPointingGuard must validate original_url"
grep -q 'isNodePublishableHost' "$cname" || fail "CnamePrecheckService must reject unpublishable hosts"

grep -q 'WithoutOverlapping' "${REPO_ROOT}/docs/OPS-MULTI-WORKER-QUEUE.md" \
  || fail "OPS-MULTI-WORKER-QUEUE must document WithoutOverlapping"

grep -q "where('admin_id'" "$queue" || fail "DomainCertificateQueue must scope by admin_id"
grep -q "where('admin_id'" "$redeploy" || fail "DomainCertificateRedeployQueue must scope by admin_id"
grep -q 'AdminTenantContext' "$queue" || fail "DomainCertificateQueue must use AdminTenantContext"
grep -q 'RediAdminDomain::query()' "$exporter" || fail "CSV export must use tenant-scoped model query"
grep -q "addGlobalScope('admin_tenant'" "$tenantTrait" || fail "BelongsToAdminTenant global scope required"

revision="${APP}/Services/NodeAgentRevisionService.php"
controller="${APP}/Http/Controllers/Api/NodeAgentController.php"
[[ -f "$revision" ]] || fail "missing NodeAgentRevisionService"
[[ -f "$controller" ]] || fail "missing NodeAgentController"

grep -q 'certIsNodePullable' "$revision" || fail "revision must filter pullable certs"
grep -q 'pruneStaleDeploymentsForNode' "$controller" || fail "node agent must prune stale deployments"

echo "verify-tenant-edge-queue-invariants: OK"
