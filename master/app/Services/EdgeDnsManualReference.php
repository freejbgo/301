<?php

namespace App\Services;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
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
            $ips = RediNode::enabled()->orderBy('id')->pluck('public_ip')->all();
            if ($ips === []) {
                $pool[] = ['A', $poolFqdn, '（请先在节点管理添加启用节点 IP）'];
            } else {
                foreach ($ips as $ip) {
                    $pool[] = ['A', $poolFqdn, $ip];
                }
            }
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
                    $fqdn = strtolower(rtrim($edge->edge_fqdn, '.'));
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
}
