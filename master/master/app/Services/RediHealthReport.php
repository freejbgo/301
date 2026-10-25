<?php

namespace App\Services;

use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Models\RediTenantEdge;
use App\Services\Dns\DnsProviderFactory;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

class RediHealthReport
{
    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    public static function collectIssues(int $warnDays = 7): array
    {
        $warnDays = min(365, max(1, $warnDays));
        $issues = [];

        foreach (self::productionConfigIssues() as $issue) {
            $issues[] = $issue;
        }

        foreach (self::nodePoolIssues() as $issue) {
            $issues[] = $issue;
        }

        $staleNodes = RediNode::query()
            ->enabled()
            ->where(function ($query) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subMinutes(15));
            })
            ->orderBy('id')
            ->get(['id', 'name', 'public_ip', 'last_heartbeat_at']);

        foreach ($staleNodes as $node) {
            $params = [
                'id' => $node->id,
                'name' => OpsDisplaySanitizer::field($node->name),
                'ip' => OpsDisplaySanitizer::field($node->public_ip),
            ];
            $issues[] = [
                'level' => 'warning',
                'code' => 'node_stale',
                'params' => $params,
                'message' => self::issueMessage(['code' => 'node_stale', 'params' => $params]),
            ];
        }

        $pendingDeploys = CertificateDeployService::countActionableDeployments('pending');

        if ($pendingDeploys > 0) {
            $params = ['count' => $pendingDeploys];
            $issues[] = [
                'level' => 'warning',
                'code' => 'cert_deploy_pending',
                'params' => $params,
                'message' => self::issueMessage(['code' => 'cert_deploy_pending', 'params' => $params]),
            ];
        }

        $failedDeploys = CertificateDeployService::countActionableDeployments('failed');

        if ($failedDeploys > 0) {
            $params = ['count' => $failedDeploys];
            $issues[] = [
                'level' => 'critical',
                'code' => 'cert_deploy_failed',
                'params' => $params,
                'message' => self::issueMessage(['code' => 'cert_deploy_failed', 'params' => $params]),
            ];
        }

        foreach (self::certDomainNameDriftIssues() as $issue) {
            $issues[] = $issue;
        }

        $expiredCerts = RediDomainCert::query()
            ->whereHas('domain', fn ($q) => $q->where('status', 1))
            ->whereIn('status', [
                RediDomainCert::STATUS_ACTIVE,
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->with('domain')
            ->get(['id', 'domain_id', 'domain_name', 'expires_at', 'status']);

        foreach ($expiredCerts as $cert) {
            $host = self::certCanonicalHost($cert);
            if ($host === '') {
                continue;
            }
            $params = [
                'host' => OpsDisplaySanitizer::field($host, $host, 253),
                'date' => $cert->expires_at->format('Y-m-d'),
                'status' => $cert->status,
            ];
            $issues[] = [
                'level' => 'critical',
                'code' => 'cert_expired',
                'params' => $params,
                'message' => self::issueMessage(['code' => 'cert_expired', 'params' => $params]),
            ];
        }

        $expiringCerts = RediDomainCert::query()
            ->whereHas('domain', fn ($q) => $q->where('status', 1))
            ->whereIn('status', [
                RediDomainCert::STATUS_ACTIVE,
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
                RediDomainCert::STATUS_FAILED,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', now())
            ->where('expires_at', '<=', now()->addDays($warnDays))
            ->orderBy('expires_at')
            ->with('domain')
            ->get(['id', 'domain_id', 'domain_name', 'expires_at', 'status']);

        foreach ($expiringCerts as $cert) {
            $host = self::certCanonicalHost($cert);
            if ($host === '') {
                continue;
            }
            $params = [
                'days' => $warnDays,
                'host' => OpsDisplaySanitizer::field($host, $host, 253),
                'date' => $cert->expires_at->format('Y-m-d'),
                'status' => $cert->status,
            ];
            $issues[] = [
                'level' => 'warning',
                'code' => 'cert_expiring',
                'params' => $params,
                'message' => self::issueMessage(['code' => 'cert_expiring', 'params' => $params]),
            ];
        }

        foreach (self::certValidatingStuckIssues() as $issue) {
            $issues[] = $issue;
        }

        foreach (self::queueIssues() as $issue) {
            $issues[] = $issue;
        }

        return OpsDisplaySanitizer::sanitizeHealthIssues($issues);
    }

    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    protected static function productionConfigIssues(): array
    {
        if (config('app.env') !== 'production') {
            return [];
        }

        $issues = [];

        if (config('app.debug')) {
            $issues[] = self::makeIssue('critical', 'app_debug_enabled');
        }

        if (! Schema::hasTable('redi_platform_dns')) {
            return $issues;
        }

        $platform = RediPlatformDns::query()->find(1);
        if (! $platform) {
            $platform = RediPlatformDns::instance();
        }

        if (trim((string) $platform->acme_contact_email) === '') {
            $issues[] = self::makeIssue('warning', 'acme_email_missing');
        }

        if ($platform->acme_staging) {
            $issues[] = self::makeIssue('warning', 'acme_staging_enabled');
        }

        if (trim((string) $platform->edge_zone) === '') {
            $issues[] = self::makeIssue('warning', 'edge_zone_missing');
        }

        if ($platform->isDnsApiMode()) {
            $driver = strtolower(trim((string) $platform->dns_driver));
            if ($driver === '' || $driver === 'manual' || ! DnsProviderFactory::isSupportedApiDriver($driver)) {
                $issues[] = self::makeIssue('warning', 'dns_driver_invalid');
            }
            $creds = $platform->getApiCredentialsArray();
            if (trim((string) ($creds['api_key'] ?? '')) === '') {
                $issues[] = self::makeIssue('warning', 'dns_api_credentials_missing');
            }
            if ($driver === 'cloudflare' && trim((string) $platform->dns_zone_id) === '') {
                $issues[] = self::makeIssue('warning', 'dns_zone_id_missing');
            }
        }

        return $issues;
    }

    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    protected static function nodePoolIssues(): array
    {
        if (! Schema::hasTable('redi_nodes')) {
            return [];
        }

        $enabled = (int) RediNode::query()->enabled()->count();
        if ($enabled === 0) {
            return [self::makeIssue('warning', 'node_pool_empty')];
        }

        if (RediNode::enabledPublicIps() !== []) {
            return [];
        }

        $params = ['count' => $enabled];

        return [self::makeIssue('warning', 'node_no_public_ip', $params)];
    }

    public static function collectHints(): array
    {
        $hints = [];
        $queue = (string) config('queue.default', 'sync');
        $cache = (string) config('cache.default', 'file');

        if ($queue !== 'sync' && $cache === 'file') {
            $hints[] = self::makeHint('queue_cache_file');
        }

        if ($queue === 'database') {
            $hints[] = self::makeHint('queue_database_single_host');
        }

        if (config('app.env') === 'production' && trim((string) config('redi.min_agent_version')) === '') {
            $hints[] = self::makeHint('agent_version_gate_off');
        }

        foreach (self::dnsApiTenantSyncHints() as $hint) {
            $hints[] = $hint;
        }

        foreach ($hints as $i => $hint) {
            $hints[$i]['message'] = OpsDisplaySanitizer::stripControlChars(self::hintMessage($hint));
        }

        return $hints;
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    protected static function dnsApiTenantSyncHints(): array
    {
        if (! Schema::hasTable('redi_tenant_edge') || ! Schema::hasTable('redi_platform_dns') || ! Schema::hasTable('redi_admin_domain')) {
            return [];
        }
        if (! Schema::hasColumn('redi_admin_domain', 'admin_id')) {
            return [];
        }

        $platform = RediPlatformDns::query()->find(1);
        if (! $platform || ! $platform->isDnsApiMode()) {
            return [];
        }

        $count = (int) RediTenantEdge::query()
            ->whereNull('dns_synced_at')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('redi_admin_domain as d')
                    ->whereColumn('d.admin_id', 'redi_tenant_edge.admin_id');
            })
            ->count();

        if ($count < 1) {
            return [];
        }

        return [self::makeHint('dns_api_tenants_unsynced', ['count' => $count])];
    }

    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    protected static function certValidatingStuckIssues(): array
    {
        $hours = max(1, (int) config('redi.health_validating_stuck_hours', 2));
        $cutoff = now()->subHours($hours);

        $base = RediDomainCert::query()
            ->where('status', RediDomainCert::STATUS_VALIDATING)
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('domain', fn ($q) => $q->where('status', 1));

        $reportable = (clone $base)
            ->with('domain')
            ->orderBy('updated_at')
            ->get(['id', 'domain_id', 'domain_name', 'updated_at'])
            ->filter(fn (RediDomainCert $row) => self::certCanonicalHost($row) !== '');

        $total = $reportable->count();
        if ($total === 0) {
            return [];
        }

        $sample = $reportable
            ->take(20)
            ->map(fn (RediDomainCert $row) => self::certCanonicalHost($row))
            ->all();

        $names = implode(', ', array_map(
            fn ($name) => OpsDisplaySanitizer::field((string) $name, (string) $name, 253),
            $sample
        ));
        if ($total > count($sample)) {
            $names .= __('redi-health.issues.cert_validating_stuck_more', ['total' => $total]);
        }

        $params = ['total' => $total, 'hours' => $hours, 'names' => $names];

        return [self::makeIssue('warning', 'cert_validating_stuck', $params)];
    }

    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    protected static function queueIssues(): array
    {
        $issues = [];
        $connection = (string) config('queue.default', 'sync');
        if ($connection === 'sync') {
            return $issues;
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = (int) \DB::table('failed_jobs')->count();
            if ($failed > 0) {
                $issues[] = self::makeIssue('critical', 'queue_failed_jobs', ['count' => $failed]);
            }
        }

        if (Schema::hasTable('jobs')) {
            $pending = (int) \DB::table('jobs')->count();
            $threshold = max(10, (int) config('redi.health_queue_pending_threshold', 80));
            if ($pending >= $threshold) {
                $issues[] = self::makeIssue('warning', 'queue_backlog', [
                    'pending' => $pending,
                    'threshold' => $threshold,
                ]);
            }
        }

        if ($connection === 'database' && Schema::hasTable('jobs')) {
            $staleHours = max(1, (int) config('redi.health_queue_stale_hours', 2));
            $stale = (int) \DB::table('jobs')
                ->where('available_at', '<', now()->subHours($staleHours)->timestamp)
                ->count();
            if ($stale > 0) {
                $issues[] = self::makeIssue('warning', 'queue_stale', [
                    'count' => $stale,
                    'hours' => $staleHours,
                ]);
            }
        }

        return $issues;
    }

    /**
     * @return array{queue_connection: string, cache_driver: string, enabled_nodes: int, stale_nodes: int, pending_deploys: int, failed_deploys: int, queue_pending: int, queue_failed: int, health_queue_pending_threshold: int, health_queue_stale_hours: int, health_validating_stuck_hours: int, health_renew_validating_stuck_hours: int}
     */
    public static function summary(): array
    {
        $enabled = RediNode::query()->enabled()->count();
        $stale = RediNode::query()
            ->enabled()
            ->where(function ($query) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subMinutes(15));
            })
            ->count();

        $queuePending = Schema::hasTable('jobs') ? (int) \DB::table('jobs')->count() : 0;
        $queueFailed = Schema::hasTable('failed_jobs') ? (int) \DB::table('failed_jobs')->count() : 0;

        return [
            'queue_connection' => (string) config('queue.default', 'sync'),
            'cache_driver' => (string) config('cache.default', 'file'),
            'enabled_nodes' => $enabled,
            'stale_nodes' => $stale,
            'pending_deploys' => CertificateDeployService::countActionableDeployments('pending'),
            'failed_deploys' => CertificateDeployService::countActionableDeployments('failed'),
            'queue_pending' => $queuePending,
            'queue_failed' => $queueFailed,
            'health_queue_pending_threshold' => max(10, (int) config('redi.health_queue_pending_threshold', 80)),
            'health_queue_stale_hours' => max(1, (int) config('redi.health_queue_stale_hours', 2)),
            'health_validating_stuck_hours' => max(1, (int) config('redi.health_validating_stuck_hours', 2)),
            'health_renew_validating_stuck_hours' => max(1, (int) config('redi.renew_validating_stuck_hours', 3)),
        ];
    }

    protected static function certCanonicalHost(RediDomainCert $cert): string
    {
        $cert->loadMissing('domain');

        return DomainRecordNormalizer::originalUrlIfValid((string) optional($cert->domain)->original_url ?? '');
    }

    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    protected static function certDomainNameDriftIssues(): array
    {
        $statuses = [
            RediDomainCert::STATUS_ACTIVE,
            RediDomainCert::STATUS_ISSUED,
            RediDomainCert::STATUS_DEPLOYING,
            RediDomainCert::STATUS_VALIDATING,
            RediDomainCert::STATUS_FAILED,
        ];

        $issues = [];
        RediDomainCert::query()
            ->with('domain')
            ->whereIn('status', $statuses)
            ->whereHas('domain', fn ($q) => $q->where('status', 1))
            ->orderBy('id')
            ->get(['id', 'domain_id', 'domain_name', 'status'])
            ->each(function (RediDomainCert $cert) use (&$issues) {
                if (self::certCanonicalHost($cert) === '') {
                    return;
                }
                $recordedHost = HostNameValidator::toCanonicalAscii((string) $cert->domain_name);
                DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert, false);
                if (DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
                    $alignedHost = HostNameValidator::toCanonicalAscii((string) $cert->domain_name);
                    if ($recordedHost !== $alignedHost) {
                        CertificateDeployService::freshCertWithPersistedRealign($cert);
                    }

                    return;
                }
                $healed = CertificateDeployService::freshCertWithPersistedRealign($cert);
                if ($healed && DomainRecordNormalizer::certMatchesAdminDomain($healed)) {
                    return;
                }
                if ($healed) {
                    $cert = $healed;
                }
                $canonical = self::certCanonicalHost($cert);
                $params = [
                    'id' => $cert->id,
                    'recorded' => OpsDisplaySanitizer::field($cert->domain_name, (string) $cert->domain_name, 253),
                    'canonical' => OpsDisplaySanitizer::field($canonical, $canonical, 253),
                ];
                $issues[] = self::makeIssue('warning', 'cert_domain_name_drift', $params);
            });

        return $issues;
    }

    /**
     * @return Collection<int, object{node_id: int, pending_count: int, failed_count: int}>
     */
    public static function deploymentCountsByNode(): Collection
    {
        return CertificateDeployService::deploymentCountsByNode();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{level: string, code: string, params: array<string, mixed>, message: string}
     */
    protected static function makeIssue(string $level, string $code, array $params = []): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'params' => $params,
            'message' => self::issueMessage(['code' => $code, 'params' => $params]),
        ];
    }

    /**
     * @param  array{code?: string, params?: array<string, mixed>, message?: string}  $issue
     */
    public static function issueMessage(array $issue): string
    {
        $code = (string) ($issue['code'] ?? '');
        if ($code === '') {
            return (string) ($issue['message'] ?? '');
        }

        $key = 'redi-health.issues.'.$code;
        if (Lang::has($key)) {
            return (string) __($key, (array) ($issue['params'] ?? []));
        }

        return (string) ($issue['message'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    protected static function makeHint(string $code, array $params = []): array
    {
        return [
            'code' => $code,
            'params' => $params,
            'message' => self::hintMessage(['code' => $code, 'params' => $params]),
        ];
    }

    /**
     * @param  array{code?: string, params?: array<string, mixed>, message?: string}  $hint
     */
    public static function hintMessage(array $hint): string
    {
        $code = (string) ($hint['code'] ?? '');
        if ($code === '') {
            return (string) ($hint['message'] ?? '');
        }

        $key = 'redi-health.hints.'.$code;
        if (Lang::has($key)) {
            return (string) __($key, (array) ($hint['params'] ?? []));
        }

        return (string) ($hint['message'] ?? '');
    }
}
