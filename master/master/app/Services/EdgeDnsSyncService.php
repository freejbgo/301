<?php

namespace App\Services;

use App\Models\RediDnsSyncLog;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Services\Dns\DnsProviderFactory;
use App\Services\Dns\DnsProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class EdgeDnsSyncService
{
    /**
     * @param  DnsProviderInterface|null  $providerOverride  仅测试注入
     */
    public function syncAll(?DnsProviderInterface $providerOverride = null): void
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

        $provider = $providerOverride ?? DnsProviderFactory::make($platform);
        $provider->testConnection();

        $ips = RediNode::enabledPublicIps();
        $provider->upsertARecords($poolFqdn, $ips);

        $poolHost = strtolower(trim($platform->pool_host, '.'));
        $poolTarget = $poolHost.'.'.$zone;

        $edges = RediTenantEdge::query()->where('edge_fqdn', '!=', '')->get();
        $syncedEdgeIds = [];
        $synced = 0;
        foreach ($edges as $edge) {
            $fqdn = strtolower(rtrim($edge->edge_fqdn, '.'));
            if ($fqdn === '' || ! Str::endsWith($fqdn, '.'.$zone)) {
                \Illuminate\Support\Facades\Log::warning('EdgeDnsSync: skip tenant edge (FQDN not under edge_zone)', [
                    'edge_id' => $edge->id,
                    'edge_fqdn' => $edge->edge_fqdn,
                    'edge_zone' => $zone,
                ]);
                continue;
            }
            $label = substr($fqdn, 0, -strlen('.'.$zone));
            $provider->upsertCname($label, $zone, $poolTarget);
            $syncedEdgeIds[] = $edge->id;
            $synced++;
        }

        $now = now();
        DB::transaction(function () use ($syncedEdgeIds, $now) {
            if ($syncedEdgeIds === []) {
                return;
            }
            RediTenantEdge::query()->whereIn('id', $syncedEdgeIds)->update(['dns_synced_at' => $now]);
        });

        RediDnsSyncLog::record('sync_pool_and_tenant_cname', 'ok', sprintf(
            'pool A x%d, tenant CNAME x%d',
            count($ips),
            $synced
        ));
    }
}
