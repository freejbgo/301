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
use App\Support\RediErrorMessage;
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
            throw new RuntimeException(RediErrorMessage::get('dns.configure_platform_zone'));
        }
        $zoneLabel = OpsDisplaySanitizer::field($zone, $zone, 253);

        $poolFqdn = $platform->poolFqdn();
        $poolCanonical = HostNameValidator::toCanonicalAscii($poolFqdn);
        if ($poolFqdn === '' || ! HostNameValidator::isValidHostLabel($poolCanonical)) {
            throw new RuntimeException(RediErrorMessage::get('dns.pool_fqdn_invalid'));
        }

        if (! $platform->isDnsApiMode()) {
            throw new RuntimeException(RediErrorMessage::get('dns.manual_mode_no_api'));
        }

        $edges = RediTenantEdge::query()->where('edge_fqdn', '!=', '')->get();
        $eligible = $this->eligibleTenantEdges($edges, $zone);
        $withFqdn = $edges->filter(function ($edge) {
            return trim((string) $edge->edge_fqdn) !== '';
        });
        if ($withFqdn->isNotEmpty() && $eligible->isEmpty()) {
            throw new RuntimeException(
                RediErrorMessage::get('dns.tenant_zone_skew', ['zone' => $zoneLabel])
            );
        }

        $poolHost = strtolower(trim($platform->pool_host, '.'));
        $poolTarget = HostNameValidator::toCanonicalAscii($poolHost.'.'.$zone);
        if ($poolTarget === '' || ! HostNameValidator::isValidHostLabel($poolTarget)) {
            throw new RuntimeException(RediErrorMessage::get('dns.cname_target_invalid'));
        }
        $publishAaaa = $this->publishPoolAaaaEnabled();
        if ($publishAaaa) {
            $this->assertIpv6ReadyForPoolAaaaPublish();
        }

        $ips = RediNode::enabledPublicIps();
        if ($ips === []) {
            throw new RuntimeException(RediErrorMessage::get('dns.no_enabled_ipv4'));
        }

        $this->assertNoSkewedTenantEdges($edges, $zone, $zoneLabel);

        $provider = $providerOverride ?? DnsProviderFactory::make($platform);
        $provider->testConnection();

        $aaaaInvoked = false;
        $aaaaCount = 0;

        try {
            $provider->upsertARecords($poolFqdn, $ips);
        } catch (Throwable $e) {
            throw new RuntimeException(
                RediErrorMessage::get('dns.pool_a_sync_failed', [
                    'detail' => OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
                ]),
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
                        RediErrorMessage::get('dns.pool_aaaa_sync_failed')
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
                ? RediErrorMessage::get('dns.pool_partial_hint_a_aaaa')
                : RediErrorMessage::get('dns.pool_partial_hint_a');
            throw new RuntimeException(
                RediErrorMessage::get('dns.tenant_sync_failed_pool', ['hint' => $poolHint])
                    .OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
                0,
                $e
            );
        }

        if ($skipped > 0) {
            $poolHint = $aaaaInvoked
                ? RediErrorMessage::get('dns.pool_partial_hint_a_aaaa')
                : RediErrorMessage::get('dns.pool_partial_hint_cname');
            throw new RuntimeException(
                RediErrorMessage::get('dns.tenant_sync_aborted', [
                    'count' => $skipped,
                    'zone' => $zoneLabel,
                    'hint' => $poolHint,
                ])
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

    /**
     * @param  Collection<int, RediTenantEdge>  $edges
     */
    protected function assertNoSkewedTenantEdges(Collection $edges, string $zone, string $zoneLabel): void
    {
        $skipped = 0;
        foreach ($edges as $edge) {
            $fqdn = HostNameValidator::toCanonicalAscii((string) $edge->edge_fqdn);
            if ($fqdn === '') {
                continue;
            }
            if (! HostNameValidator::isValidHostLabel($fqdn) || ! Str::endsWith($fqdn, '.'.$zone)) {
                $skipped++;
                Log::warning('EdgeDnsSync: tenant edge FQDN not under edge_zone (pre-check)', [
                    'edge_id' => $edge->id,
                    'fqdn' => OpsDisplaySanitizer::field($fqdn, '', 253),
                    'edge_zone' => OpsDisplaySanitizer::field($zone, '', 253),
                ]);
            }
        }

        if ($skipped > 0) {
            throw new RuntimeException(
                RediErrorMessage::get('dns.tenant_sync_aborted', [
                    'count' => $skipped,
                    'zone' => $zoneLabel,
                    'hint' => RediErrorMessage::get('dns.pool_partial_hint_precheck'),
                ])
            );
        }
    }

    protected function publishPoolAaaaEnabled(): bool
    {
        return filter_var(config('redi.publish_pool_aaaa', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function assertIpv6ReadyForPoolAaaaPublish(): void
    {
        if (! Schema::hasColumn('redi_nodes', 'public_ipv6')
            || ! Schema::hasColumn('redi_nodes', 'extra_ipv6')) {
            throw new RuntimeException(RediErrorMessage::get('dns.ipv6_migration_required'));
        }

        foreach (RediNode::enabled()->get() as $node) {
            if ($node->hasUnreadableIpv6()) {
                throw new RuntimeException(RediErrorMessage::get('dns.invalid_node_ipv6'));
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
