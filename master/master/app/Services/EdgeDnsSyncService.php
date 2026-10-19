<?php

namespace App\Services;

use App\Models\RediDnsSyncLog;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Services\Dns\DnsProviderFactory;
use App\Services\Dns\DnsProviderInterface;
use App\Services\Dns\SupportsPoolAAAA;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        $zoneLabel = OpsDisplaySanitizer::field($zone, $zone, 253);

        $poolFqdn = $platform->poolFqdn();
        $poolCanonical = HostNameValidator::toCanonicalAscii($poolFqdn);
        if ($poolFqdn === '' || ! HostNameValidator::isValidHostLabel($poolCanonical)) {
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
                '租户 edge 主机名均不在当前平台主域名 '.$zoneLabel.' 下，请修正 edge_fqdn 或平台主域名后再同步（尚未写入厂商 DNS）。'
            );
        }

        $poolHost = strtolower(trim($platform->pool_host, '.'));
        $poolTarget = HostNameValidator::toCanonicalAscii($poolHost.'.'.$zone);
        if ($poolTarget === '' || ! HostNameValidator::isValidHostLabel($poolTarget)) {
            throw new RuntimeException('Pool CNAME 目标 无效');
        }
        $publishAaaa = $this->publishPoolAaaaEnabled();
        if ($publishAaaa) {
            $this->assertIpv6ReadyForPoolAaaaPublish();
        }

        $ips = RediNode::enabledPublicIps();
        $aaaaInvoked = false;
        $aaaaCount = 0;

        try {
            $provider->upsertARecords($poolFqdn, $ips);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Pool A 记录同步失败：'.OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
                0,
                $e
            );
        }

        if ($publishAaaa) {
            if ($provider instanceof SupportsPoolAAAA) {
                $ipv6s = RediNode::enabledPublicIpv6s();
                $aaaaCount = count($ipv6s);
                try {
                    $provider->upsertAAAARecords($poolFqdn, $ipv6s);
                    $aaaaInvoked = true;
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        'Pool AAAA 记录同步失败（Pool A 可能已在厂商侧更新，请修正后重试）：'
                            .OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
                        0,
                        $e
                    );
                }
            } else {
                Log::warning('EdgeDnsSync: DNS provider does not implement SupportsPoolAAAA; skipping pool AAAA', [
                    'driver' => OpsDisplaySanitizer::field((string) $platform->dns_driver, '', 64),
                ]);
            }
        }

        $syncedEdgeIds = [];
        $skipped = 0;
        try {
            foreach ($edges as $edge) {
                $fqdn = HostNameValidator::toCanonicalAscii((string) $edge->edge_fqdn);
                if ($fqdn === '' || ! HostNameValidator::isValidHostLabel($fqdn)
                    || ! Str::endsWith($fqdn, '.'.$zone)) {
                    if ($fqdn !== '') {
                        $skipped++;
                        Log::warning('EdgeDnsSync: skip tenant edge (FQDN not under edge_zone)', [
                            'edge_id' => $edge->id,
                            'fqdn' => OpsDisplaySanitizer::field($fqdn, '', 253),
                            'edge_zone' => OpsDisplaySanitizer::field($zone, '', 253),
                        ]);
                    }
                    continue;
                }
                $label = substr($fqdn, 0, -strlen('.'.$zone));
                $provider->upsertCname($label, $zone, $poolTarget);
                $syncedEdgeIds[] = $edge->id;
            }
        } catch (Throwable $e) {
            $poolHint = $aaaaInvoked
                ? 'Pool A 与 AAAA 可能已在厂商侧更新，请修正后重试'
                : 'Pool A 可能已在厂商侧更新，请修正后重试';
            throw new RuntimeException(
                '租户 CNAME 同步失败（'.$poolHint.'）：'
                    .OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
                0,
                $e
            );
        }

        if ($skipped > 0) {
            $poolHint = $aaaaInvoked
                ? 'Pool A 与 AAAA 可能已在厂商侧更新，请修正后重试'
                : 'Pool A 与已成功的 CNAME 可能已在厂商侧更新，请修正后重试';
            throw new RuntimeException(
                "有 {$skipped} 条租户 edge 不在平台域名 {$zoneLabel} 下，已中止（{$poolHint}）。"
            );
        }

        $now = now();
        DB::transaction(function () use ($syncedEdgeIds, $now) {
            if ($syncedEdgeIds === []) {
                return;
            }
            RediTenantEdge::query()->whereIn('id', $syncedEdgeIds)->update(['dns_synced_at' => $now]);
        });

        $logMessage = sprintf(
            'pool A x%d, tenant CNAME x%d',
            count($ips),
            count($syncedEdgeIds)
        );
        if ($aaaaInvoked) {
            $logMessage = sprintf(
                'pool A x%d, tenant CNAME x%d, AAAA x%d',
                count($ips),
                count($syncedEdgeIds),
                $aaaaCount
            );
        }

        RediDnsSyncLog::record('sync_pool_and_tenant_cname', 'ok', $logMessage);
    }

    protected function publishPoolAaaaEnabled(): bool
    {
        return filter_var(config('redi.publish_pool_aaaa', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function assertIpv6ReadyForPoolAaaaPublish(): void
    {
        if (! Schema::hasColumn('redi_nodes', 'public_ipv6')
            || ! Schema::hasColumn('redi_nodes', 'extra_ipv6')) {
            throw new RuntimeException('节点 IPv6 字段尚未迁移，无法发布 Pool AAAA');
        }

        foreach (RediNode::enabled()->get() as $node) {
            if ($node->hasUnreadableIpv6()) {
                throw new RuntimeException('启用节点存在无法发布的 IPv6 地址，请先修正后台节点配置');
            }
        }
    }

    /**
     * @param  Collection<int, RediTenantEdge>  $edges
     * @return Collection<int, RediTenantEdge>
     */
    protected function eligibleTenantEdges(Collection $edges, string $zone): Collection
    {
        return $edges->filter(function ($edge) use ($zone) {
            $fqdn = HostNameValidator::toCanonicalAscii((string) $edge->edge_fqdn);

            return $fqdn !== '' && HostNameValidator::isValidHostLabel($fqdn)
                && Str::endsWith($fqdn, '.'.$zone);
        });
    }
}
