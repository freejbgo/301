<?php

namespace App\Services;

use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use Illuminate\Support\Collection;

class RediHealthReport
{
    /**
     * @return array<int, array{level: string, code: string, message: string}>
     */
    public static function collectIssues(int $warnDays = 7): array
    {
        $warnDays = max(1, $warnDays);
        $issues = [];

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
                    $node->name ?: '—',
                    $node->public_ip ?: '—'
                ),
            ];
        }

        $pendingDeploys = (int) RediCertDeployment::query()
            ->where('status', 'pending')
            ->whereHas('node', function ($query) {
                $query->where('enabled', true);
            })
            ->count();

        if ($pendingDeploys > 0) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'cert_deploy_pending',
                'message' => sprintf('仍有 %d 条证书部署待节点拉取/确认（pending）', $pendingDeploys),
            ];
        }

        $failedDeploys = (int) RediCertDeployment::query()
            ->where('status', 'failed')
            ->whereHas('node', function ($query) {
                $query->where('enabled', true);
            })
            ->count();

        if ($failedDeploys > 0) {
            $issues[] = [
                'level' => 'critical',
                'code' => 'cert_deploy_failed',
                'message' => sprintf('有 %d 条证书在节点上安装失败（failed）', $failedDeploys),
            ];
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
            ->get(['id', 'domain_name', 'expires_at', 'status']);

        foreach ($expiredCerts as $cert) {
            $issues[] = [
                'level' => 'critical',
                'code' => 'cert_expired',
                'message' => sprintf(
                    '证书已过期：%s（%s，状态 %s）',
                    $cert->domain_name,
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
            ->get(['id', 'domain_name', 'expires_at', 'status']);

        foreach ($expiringCerts as $cert) {
            $issues[] = [
                'level' => 'warning',
                'code' => 'cert_expiring',
                'message' => sprintf(
                    '证书将在 %d 天内到期：%s（%s，状态 %s）',
                    $warnDays,
                    $cert->domain_name,
                    $cert->expires_at->format('Y-m-d'),
                    $cert->status
                ),
            ];
        }

        return $issues;
    }

    /**
     * @return array{enabled_nodes: int, stale_nodes: int, pending_deploys: int, failed_deploys: int}
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

        return [
            'enabled_nodes' => $enabled,
            'stale_nodes' => $stale,
            'pending_deploys' => (int) RediCertDeployment::query()
                ->where('status', 'pending')
                ->whereHas('node', fn ($q) => $q->where('enabled', true))
                ->count(),
            'failed_deploys' => (int) RediCertDeployment::query()
                ->where('status', 'failed')
                ->whereHas('node', fn ($q) => $q->where('enabled', true))
                ->count(),
        ];
    }

    /**
     * @return Collection<int, object{node_id: int, pending_count: int, failed_count: int}>
     */
    public static function deploymentCountsByNode(): Collection
    {
        return RediCertDeployment::query()
            ->selectRaw("node_id, sum(case when status = 'pending' then 1 else 0 end) as pending_count, sum(case when status = 'failed' then 1 else 0 end) as failed_count")
            ->groupBy('node_id')
            ->get();
    }
}
