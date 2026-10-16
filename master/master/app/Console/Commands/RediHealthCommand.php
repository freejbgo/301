<?php

namespace App\Console\Commands;

use App\Services\RediHealthNotifier;
use App\Services\RediHealthReport;
use Illuminate\Console\Command;

class RediHealthCommand extends Command
{
    protected $signature = 'redi:health
                            {--days=7 : 证书到期预警天数}
                            {--json : 以 JSON 输出（供监控采集）}
                            {--notify : 有 issues 时 POST webhook；成功或无 issues 时退出 0（Cron 用）}';

    protected $description = '检查节点心跳与证书到期等运营风险（无 --notify 时，非 0 表示存在待处理项）';

    public function handle(): int
    {
        $warnDays = min(365, max(1, (int) $this->option('days')));
        $issues = RediHealthReport::collectIssues($warnDays);
        $hasIssues = $issues !== [];

        $notifyDelivered = true;
        if ($this->option('notify') && $hasIssues) {
            $notifyDelivered = RediHealthNotifier::notifyWebhook($issues);
            if ($notifyDelivered) {
                $this->info('已发送 health webhook 通知。');
            } else {
                $this->warn('未发送 webhook（未配置 URL 或请求失败）。');
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => ! $hasIssues,
                'checked_at' => now()->toIso8601String(),
                'summary' => RediHealthReport::summary(),
                'hints' => RediHealthReport::collectHints(),
                'issues' => $issues,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($hasIssues) {
            $this->warn('发现 '.count($issues).' 项待关注：');
            foreach ($issues as $row) {
                $prefix = $row['level'] === 'critical' ? '[严重]' : '[注意]';
                $this->line($prefix.' '.$row['message']);
            }
        } else {
            $this->info('redi:health 未发现待处理项。');
        }

        if (! $this->option('json')) {
            self::printHints($this);
        }

        // Cron --notify：webhook 成功则退出 0；未配置/发送失败时退出 1，由 || redi:ops-webhook 兜底。
        if ($this->option('notify')) {
            return ($hasIssues && ! $notifyDelivered) ? self::FAILURE : self::SUCCESS;
        }

        return $hasIssues ? self::FAILURE : self::SUCCESS;
    }

    protected static function printHints(Command $command): void
    {
        $hints = RediHealthReport::collectHints();
        if ($hints === []) {
            return;
        }
        $command->line('');
        $command->line('配置建议（不影响退出码）：');
        foreach ($hints as $hint) {
            $command->line('  · '.$hint['message']);
        }
    }
}
