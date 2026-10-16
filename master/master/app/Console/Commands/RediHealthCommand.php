<?php

namespace App\Console\Commands;

use App\Models\RediDomainCert;
use App\Models\RediNode;
use Illuminate\Console\Command;

class RediHealthCommand extends Command
{
    protected $signature = 'redi:health
                            {--days=7 : 证书到期预警天数}
                            {--json : 以 JSON 输出（供监控采集）}';

    protected $description = '检查节点心跳与证书到期等运营风险（非 0 退出码表示存在待处理项）';

    public function handle(): int
    {
        $warnDays = max(1, (int) $this->option('days'));
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

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $issues === [],
                'checked_at' => now()->toIso8601String(),
                'issues' => $issues,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($issues === []) {
            $this->info('redi:health 未发现待处理项。');

            return self::SUCCESS;
        }

        $this->warn('发现 '.count($issues).' 项待关注：');
        foreach ($issues as $row) {
            $prefix = $row['level'] === 'critical' ? '[严重]' : '[注意]';
            $this->line($prefix.' '.$row['message']);
        }

        return self::FAILURE;
    }
}
