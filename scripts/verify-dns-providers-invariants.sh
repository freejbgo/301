#!/usr/bin/env bash
# DNS API 驱动与 Edge 同步工厂约定（防重构漂移）。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DNS="${REPO_ROOT}/master/master/app/Services/Dns"
SYNC="${REPO_ROOT}/master/master/app/Services/EdgeDnsSyncService.php"

fail() {
  echo "verify-dns-providers-invariants: $*" >&2
  exit 1
}

factory="${DNS}/DnsProviderFactory.php"
[[ -f "$factory" ]] || fail "missing DnsProviderFactory"
[[ -f "$SYNC" ]] || fail "missing EdgeDnsSyncService"

grep -q 'API_DRIVERS' "$factory" || fail "factory must list API_DRIVERS"
grep -q 'isSupportedApiDriver' "$factory" || fail "factory must validate drivers"
grep -q 'DnsProviderFactory' "$SYNC" || fail "EdgeDnsSync must use DnsProviderFactory"

for driver in cloudflare aliyun_cn aliyun_intl dnspod_cn dnspod_intl dns_com; do
  grep -q "case '${driver}':" "$factory" || fail "missing factory case: ${driver}"
done

for cls in CloudflareDnsProvider AliyunDnsProvider DnspodDnsProvider DnsComDnsProvider; do
  [[ -f "${DNS}/${cls}.php" ]] || fail "missing ${cls}.php"
  grep -q 'ReconcilesDnsRecords' "${DNS}/${cls}.php" || fail "${cls} must use ReconcilesDnsRecords"
done

grep -q 'SupportsPoolAAAA' "${DNS}/CloudflareDnsProvider.php" || fail "Cloudflare must support pool AAAA"

echo "verify-dns-providers-invariants: OK"
