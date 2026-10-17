<?php

namespace App\Services;

use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Services\Dns\DnsProviderFactory;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Collection;
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
            $issues[] = [
                'level' => 'warning',
                'code' => 'node_stale',
                'message' => sprintf(
                    '节点 #%d %s (%s) 超过 15 分钟无心跳',
                    $node->id,
                    OpsDisplaySanitizer::field($node->name),
                    OpsDisplaySanitizer::field($node->public_ip)
                ),
            ];
        }

        $pendingDeploys = CertificateDeployService::countActionableDeployments('pending');

        if ($pendingDeploys > 0) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'cert_deploy_pending',
                'message' => sprintf('仍有 %d 条证书部署待节点拉取/确认（pending）', $pendingDeploys),
            ];
        }

        $failedDeploys = CertificateDeployService::countActionableDeployments('failed');

        if ($failedDeploys > 0) {
            $issues[] = [
                'level' => 'critical',
                'code' => 'cert_deploy_failed',
                'message' => sprintf('有 %d 条证书在节点上安装失败（failed）', $failedDeploys),
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
            $issues[] = [
                'level' => 'critical',
                'code' => 'cert_expired',
                'message' => sprintf(
                    '证书已过期：%s（%s，状态 %s）',
                    OpsDisplaySanitizer::field($host, $host, 253),
                    $cert->expires_at->format('Y-m-d'),
                    $cert->status
                ),
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
            $issues[] = [
                'level' => 'warning',
                'code' => 'cert_expiring',
                'message' => sprintf(
                    '证书将在 %d 天内到期：%s（%s，状态 %s）',
                    $warnDays,
                    OpsDisplaySanitizer::field($host, $host, 253),
                    $cert->expires_at->format('Y-m-d'),
                    $cert->status
                ),
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
            $issues[] = [
                'level' => 'critical',
                'code' => 'app_debug_enabled',
                'message' => 'APP_DEBUG=true：生产环境请关闭调试并执行 php artisan config:cache',
            ];
        }

        if (! Schema::hasTable('redi_platform_dns')) {
            return $issues;
        }

        $platform = RediPlatformDns::query()->find(1);
        if (! $platform) {
            $platform = RediPlatformDns::instance();
        }

        if (trim((string) $platform->acme_contact_email) === '') {
            $issues[] = [
                'level' => 'warning',
                'code' => 'acme_email_missing',
                'message' => '未配置 ACME 联系邮箱（后台 DNS 与节点池），无法签发证书',
            ];
        }

        if ($platform->acme_staging) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'acme_staging_enabled',
                'message' => '生产环境仍开启 ACME Staging；正式证书请关闭「ACME 测试环境」',
            ];
        }

        if (trim((string) $platform->edge_zone) === '') {
            $issues[] = [
                'level' => 'warning',
                'code' => 'edge_zone_missing',
                'message' => '未配置平台 edge 主域名（edge_zone），租户 CNAME 与证书流程不完整',
            ];
        }

        if ($platform->isDnsApiMode()) {
            $driver = strtolower(trim((string) $platform->dns_driver));
            if ($driver === '' || $driver === 'manual' || ! DnsProviderFactory::isSupportedApiDriver($driver)) {
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'dns_driver_invalid',
                    'message' => 'DNS 为 API 自动模式但驱动无效（请在后台 DNS 与节点池选择具体厂商驱动）',
                ];
            }
            $creds = $platform->getApiCredentialsArray();
            if (trim((string) ($creds['api_key'] ?? '')) === '') {
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'dns_api_credentials_missing',
                    'message' => 'DNS 为 API 自动模式但未保存 API 凭证，无法同步 pool / 租户 CNAME',
                ];
            }
            if ($driver === 'cloudflare' && trim((string) $platform->dns_zone_id) === '') {
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'dns_zone_id_missing',
                    'message' => 'Cloudflare 驱动未配置 Zone ID，API 同步将失败',
                ];
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
            return [[
                'level' => 'warning',
                'code' => 'node_pool_empty',
                'message' => '没有启用的边缘节点，证书无法部署、pool DNS 无 A 记录可同步',
            ]];
        }

        if (RediNode::enabledPublicIps() !== []) {
            return [];
        }

        return [[
            'level' => 'warning',
            'code' => 'node_no_public_ip',
            'message' => sprintf(
                '有 %d 台启用节点但未配置公网 IP（public_ip / extra_ips），pool A 记录与 A 池 CNAME 预检将无法工作',
                $enabled
            ),
        ]];
    }

    public static function collectHints(): array
    {
        $hints = [];
        $queue = (string) config('queue.default', 'sync');
        $cache = (string) config('cache.default', 'file');

        if ($queue !== 'sync' && $cache === 'file') {
            $hints[] = [
                'code' => 'queue_cache_file',
                'message' => 'QUEUE_CONNECTION 非 sync 且 CACHE_DRIVER=file：仅适合单台机器单个 queue:work；多 worker 或多机请改 CACHE_DRIVER=redis 并配置 REDIS_*（IssueDomainCertificateJob 的 WithoutOverlapping 依赖共享缓存锁）',
            ];
        }

        if ($queue === 'database') {
            $hints[] = [
                'code' => 'queue_database_single_host',
                'message' => '当前队列为 database：请确保 systemd redi-queue 持续运行；扩容 worker 前先将缓存改为 redis',
            ];
        }

        if (config('app.env') === 'production' && trim((string) config('redi.min_agent_version')) === '') {
            $hints[] = [
                'code' => 'agent_version_gate_off',
                'message' => 'REDI_MIN_AGENT_VERSION 为空：节点 Agent 版本门控已关闭，升级节点后请改回 0.1.3',
            ];
        }

        foreach ($hints as $i => $hint) {
            $hints[$i]['message'] = OpsDisplaySanitizer::stripControlChars((string) ($hint['message'] ?? ''));
        }

        return $hints;
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
            $names .= sprintf(' …等共 %d 个', $total);
        }

        return [[
            'level' => 'warning',
            'code' => 'cert_validating_stuck',
            'message' => sprintf(
                '%d 张证书超过 %d 小时仍为 validating（可能 ACME 任务卡住）：%s',
                $total,
                $hours,
                $names
            ),
        ]];
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
                $issues[] = [
                    'level' => 'critical',
                    'code' => 'queue_failed_jobs',
                    'message' => sprintf('队列有 %d 条 failed_jobs，请执行 php artisan queue:failed 排查', $failed),
                ];
            }
        }

        if (Schema::hasTable('jobs')) {
            $pending = (int) \DB::table('jobs')->count();
            $threshold = max(10, (int) config('redi.health_queue_pending_threshold', 80));
            if ($pending >= $threshold) {
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'queue_backlog',
                    'message' => sprintf(
                        '队列积压 %d 条（阈值 %d），请确认 redi-queue / queue:work 在运行',
                        $pending,
                        $threshold
                    ),
                ];
            }
        }

        if ($connection === 'database' && Schema::hasTable('jobs')) {
            $staleHours = max(1, (int) config('redi.health_queue_stale_hours', 2));
            $stale = (int) \DB::table('jobs')
                ->where('available_at', '<', now()->subHours($staleHours)->timestamp)
                ->count();
            if ($stale > 0) {
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'queue_stale',
                    'message' => sprintf(
                        '有 %d 条队列任务超过 %d 小时未执行，可能 worker 未运行',
                        $stale,
                        $staleHours
                    ),
                ];
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
                DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert);
                if (DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
                    return;
                }
                $canonical = self::certCanonicalHost($cert);
                $issues[] = [
                    'level' => 'warning',
                    'code' => 'cert_domain_name_drift',
                    'message' => sprintf(
                        '证书 #%d domain_name 与域名不一致且未能自动对齐：记录 %s，应为 %s',
                        $cert->id,
                        OpsDisplaySanitizer::field($cert->domain_name, (string) $cert->domain_name, 253),
                        OpsDisplaySanitizer::field($canonical, $canonical, 253)
                    ),
                ];
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
}
