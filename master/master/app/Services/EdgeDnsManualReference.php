<?php

namespace App\Services;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Support\HostNameValidator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 手动解析模式：在后台展示需在 DNS 厂商面板配置的记录（不调用厂商 API）。
 */
class EdgeDnsManualReference
{
    /**
     * @return array{pool: array<int, array{0: string, 1: string, 2: string}>, tenant: array<int, array{0: string, 1: string, 2: string}>}
     */
    public static function tables(): array
    {
        $platform = RediPlatformDns::instance();
        $poolFqdn = $platform->poolFqdn();
        $zone = strtolower(trim($platform->edge_zone, '.'));
        $poolHost = strtolower(trim($platform->pool_host, '.'));

        $pool = [];
        if ($poolFqdn !== '') {
            $ips = RediNode::enabledPublicIps();
            if ($ips === []) {
                $pool[] = ['A', $poolFqdn, '（请先在节点管理添加启用节点 IP）'];
            } else {
                foreach ($ips as $ip) {
                    $pool[] = ['A', $poolFqdn, $ip];
                }
            }
            $pool = array_merge($pool, self::poolAaaaRows($poolFqdn));
        }

        $tenant = [];
        if ($zone !== '' && $poolHost !== '') {
            $poolTarget = $poolHost.'.'.$zone;
            $edges = RediTenantEdge::query()
                ->where('edge_fqdn', '!=', '')
                ->orderBy('admin_id')
                ->get();
            if ($edges->isEmpty()) {
                $tenant[] = ['CNAME', '（租户 token）', $poolTarget.'（租户登录并添加域名后生成）'];
            } else {
                foreach ($edges as $edge) {
                    $fqdn = HostNameValidator::toCanonicalAscii((string) $edge->edge_fqdn);
                    if ($fqdn === '' || ! HostNameValidator::isValidHostLabel($fqdn)) {
                        continue;
                    }
                    $rr = $edge->token;
                    if ($fqdn !== '' && Str::endsWith($fqdn, '.'.$zone)) {
                        $rr = substr($fqdn, 0, -strlen('.'.$zone));
                    }
                    $tenant[] = ['CNAME', $rr.'（'.$fqdn.'）', $poolTarget];
                }
            }
        }

        return ['pool' => $pool, 'tenant' => $tenant];
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    protected static function poolAaaaRows(string $poolFqdn): array
    {
        if (! filter_var(config('redi.publish_pool_aaaa', false), FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }
        if (! Schema::hasColumn('redi_nodes', 'public_ipv6')
            || ! Schema::hasColumn('redi_nodes', 'extra_ipv6')) {
            return [];
        }

        $rows = [];
        foreach (RediNode::enabledPublicIpv6s() as $ip) {
            $rows[] = ['AAAA', $poolFqdn, $ip];
        }

        $hasUnreadable = false;
        foreach (RediNode::enabled()->get() as $node) {
            if ($node->hasUnreadableIpv6()) {
                $hasUnreadable = true;
                break;
            }
        }
        if ($hasUnreadable) {
            $rows[] = ['AAAA', $poolFqdn, '（部分启用节点 IPv6 无法解析，请先在后台修正）'];
        }

        return $rows;
    }
}
