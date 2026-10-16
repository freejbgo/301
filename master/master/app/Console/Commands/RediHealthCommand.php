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
                            {--notify : 有问题时 POST 到 REDI_HEALTH_WEBHOOK_URL}';

    protected $description = '检查节点心跳与证书到期等运营风险（非 0 退出码表示存在待处理项）';

    public function handle(): int
    {
        $warnDays = max(1, (int) $this->option('days'));
        $issues = RediHealthReport::collectIssues($warnDays);

        if ($this->option('notify') && $issues !== []) {
            if (RediHealthNotifier::notifyWebhook($issues)) {
                $this->info('已发送 health webhook 通知。');
            } else {
                $this->warn('未发送 webhook（未配置 URL 或请求失败）。');
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $issues === [],
                'checked_at' => now()->toIso8601String(),
                'summary' => RediHealthReport::summary(),
                'hints' => RediHealthReport::collectHints(),
                'issues' => $issues,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($issues === []) {
            $this->info('redi:health 未发现待处理项。');
        } else {
            $this->warn('发现 '.count($issues).' 项待关注：');
            foreach ($issues as $row) {
                $prefix = $row['level'] === 'critical' ? '[严重]' : '[注意]';
                $this->line($prefix.' '.$row['message']);
            }
        }

        self::printHints($this);

        return $issues === [] ? self::SUCCESS : self::FAILURE;
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
