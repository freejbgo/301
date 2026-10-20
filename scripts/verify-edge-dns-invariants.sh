#!/usr/bin/env bash
# 静态校验 Edge DNS 同步与 Pool A/AAAA  reconcile 约定。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() {
  echo "verify-edge-dns-invariants: $*" >&2
  exit 1
}

reconcile="${REPO_ROOT}/master/master/app/Services/Dns/Concerns/ReconcilesDnsRecords.php"
sync="${REPO_ROOT}/master/master/app/Services/EdgeDnsSyncService.php"
cf="${REPO_ROOT}/master/master/app/Services/Dns/CloudflareDnsProvider.php"

for f in "$reconcile" "$sync" "$cf"; do
  [[ -f "$f" ]] || fail "missing $f"
done

grep -q '没有可同步的节点 IP' "$reconcile" || fail "A reconcile must reject empty pool IPv4"
grep -q 'reconcileAAAARecordValues' "$reconcile" || fail "missing AAAA reconcile"
grep -q 'enabledPublicIps' "$sync" || fail "EdgeDnsSyncService must use enabledPublicIps"
grep -q '没有启用的节点公网 IPv4' "$sync" || fail "EdgeDnsSyncService must fail fast without pool IPv4"
grep -q 'orangeCloudAaaaIds' "$cf" || fail "Cloudflare must strip proxied AAAA before reconcile"

echo "verify-edge-dns-invariants: OK"
