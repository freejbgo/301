<?php

namespace App\Services;

use App\Models\RediDnsSyncLog;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Services\Dns\DnsProviderFactory;
use Illuminate\Support\Str;
use RuntimeException;

class EdgeDnsSyncService
{
    public function syncAll(): void
    {
        $platform = RediPlatformDns::instance();
        $zone = trim($platform->edge_zone, '.');
        if ($zone === '') {
            throw new RuntimeException('请先配置平台主域名');
        }

        $poolFqdn = $platform->poolFqdn();
        if ($poolFqdn === '') {
            throw new RuntimeException('Pool FQDN 无效');
        }

        if ($platform->dns_mode !== 'api') {
            throw new RuntimeException('当前为手动解析模式：请在「DNS 与节点池」参考表中到 DNS 服务商自行配置，勿调用 API 同步。');
        }

        $provider = DnsProviderFactory::make($platform);
        $provider->testConnection();

        $ips = RediNode::enabledPublicIps();
        $provider->upsertARecords($poolFqdn, $ips);

        $poolHost = strtolower(trim($platform->pool_host, '.'));
        $poolTarget = $poolHost.'.'.$zone;

        $edges = RediTenantEdge::query()->where('edge_fqdn', '!=', '')->get();
        $synced = 0;
        foreach ($edges as $edge) {
            $fqdn = strtolower(rtrim($edge->edge_fqdn, '.'));
            if ($fqdn === '' || ! Str::endsWith($fqdn, '.'.$zone)) {
                continue;
            }
            $label = substr($fqdn, 0, -strlen('.'.$zone));
            $provider->upsertCname($label, $zone, $poolTarget);
            $edge->dns_synced_at = now();
            $edge->save();
            $synced++;
        }

        RediDnsSyncLog::record('sync_pool_and_tenant_cname', 'ok', sprintf(
            'pool A x%d, tenant CNAME x%d',
            count($ips),
            $synced
        ));
    }
}
