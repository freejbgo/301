<?php

namespace App\Services;

use App\Models\RediDnsSyncLog;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Services\Dns\DnsProviderFactory;
use App\Services\Dns\DnsProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

        if (! $platform->isDnsApiMode()) {
            throw new RuntimeException('当前为手动解析模式：请在「DNS 与节点池」参考表中到 DNS 服务商自行配置，勿调用 API 同步。');
        }

        $provider = $providerOverride ?? DnsProviderFactory::make($platform);
        $provider->testConnection();

        $edges = RediTenantEdge::query()->where('edge_fqdn', '!=', '')->get();
        $eligible = $this->eligibleTenantEdges($edges, $zone);
        $withFqdn = $edges->filter(function ($edge) {
            return trim((string) $edge->edge_fqdn) !== '';
        });
        if ($withFqdn->isNotEmpty() && $eligible->isEmpty()) {
            throw new RuntimeException(
                '租户 edge 主机名均不在当前平台主域名 '.$zone.' 下，请修正 edge_fqdn 或平台主域名后再同步（尚未写入厂商 DNS）。'
            );
        }

        $poolHost = strtolower(trim($platform->pool_host, '.'));
        $poolTarget = $poolHost.'.'.$zone;
        $ips = RediNode::enabledPublicIps();

        try {
            $provider->upsertARecords($poolFqdn, $ips);
        } catch (Throwable $e) {
            throw new RuntimeException('Pool A 记录同步失败：'.$e->getMessage(), 0, $e);
        }

        $syncedEdgeIds = [];
        $skipped = 0;
        try {
            foreach ($edges as $edge) {
                $fqdn = strtolower(rtrim((string) $edge->edge_fqdn, '.'));
                if ($fqdn === '' || ! Str::endsWith($fqdn, '.'.$zone)) {
                    if ($fqdn !== '') {
                        $skipped++;
                        Log::warning('EdgeDnsSync: skip tenant edge (FQDN not under edge_zone)', [
                            'edge_id' => $edge->id,
                            'edge_fqdn' => $edge->edge_fqdn,
                            'edge_zone' => $zone,
                        ]);
                    }
                    continue;
                }
                $label = substr($fqdn, 0, -strlen('.'.$zone));
                $provider->upsertCname($label, $zone, $poolTarget);
                $syncedEdgeIds[] = $edge->id;
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                '租户 CNAME 同步失败（Pool A 可能已在厂商侧更新，请修正后重试）：'.$e->getMessage(),
                0,
                $e
            );
        }

        if ($skipped > 0) {
            throw new RuntimeException(
                "有 {$skipped} 条租户 edge 不在平台域名 {$zone} 下，已中止（Pool A 与已成功的 CNAME 可能已在厂商侧更新，请修正后重试）。"
            );
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
            count($syncedEdgeIds)
        ));
    }

    /**
     * @param  Collection<int, RediTenantEdge>  $edges
     * @return Collection<int, RediTenantEdge>
     */
    protected function eligibleTenantEdges(Collection $edges, string $zone): Collection
    {
        return $edges->filter(function ($edge) use ($zone) {
            $fqdn = strtolower(rtrim((string) $edge->edge_fqdn, '.'));
            return $fqdn !== '' && Str::endsWith($fqdn, '.'.$zone);
        });
    }
}
