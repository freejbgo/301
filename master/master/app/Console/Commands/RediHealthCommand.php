<?php

namespace App\Console\Commands;

use App\Services\RediHealthNotifier;
use App\Services\RediHealthReport;
use App\Support\OpsDisplaySanitizer;
use App\Support\RediErrorMessage;
use Illuminate\Console\Command;

class RediHealthCommand extends Command
{
    protected $signature = 'redi:health
                            {--days=7 : Certificate expiry warning window (days)}
                            {--json : Output JSON for monitoring}
                            {--notify : POST webhook when issues exist; exit 0 on success or no issues (for cron)}';

    protected $description = 'Check node heartbeat, certificate expiry, and ops risks (exit non-zero when issues exist unless --notify succeeds)';

    public function handle(): int
    {
        $warnDays = min(365, max(1, (int) $this->option('days')));
        $issues = RediHealthReport::collectIssues($warnDays);
        $issuesEn = RediHealthReport::localizeIssues($issues, 'en');
        $hasIssues = $issues !== [];

        $notifyDelivered = true;
        if ($this->option('notify') && $hasIssues) {
            $notifyDelivered = RediHealthNotifier::notifyWebhook($issuesEn);
            if ($notifyDelivered) {
                $this->info(RediErrorMessage::get('cli.health_webhook_sent'));
            } else {
                $this->warn(RediErrorMessage::get('cli.health_webhook_skipped'));
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => ! $hasIssues,
                'checked_at' => now()->toIso8601String(),
                'summary' => RediHealthReport::summary(),
                'hints' => array_map(
                    fn (array $hint) => array_merge($hint, ['message' => RediHealthReport::hintMessage($hint, 'en')]),
                    RediHealthReport::collectHints()
                ),
                'issues' => $issuesEn,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($hasIssues) {
            $this->warn(RediErrorMessage::get('cli.health_issues_found', ['count' => count($issues)]));
            foreach ($issuesEn as $row) {
                $prefix = $row['level'] === 'critical'
                    ? '['.RediErrorMessage::get('health.critical').']'
                    : '['.RediErrorMessage::get('health.warning').']';
                $this->line($prefix.' '.OpsDisplaySanitizer::field($row['message'] ?? '', '—', 500));
            }
        } else {
            $this->info(RediErrorMessage::get('cli.health_no_issues'));
        }

        if (! $this->option('json')) {
            self::printHints($this);
        }

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
        $command->line(RediErrorMessage::get('cli.health_config_hints'));
        foreach ($hints as $hint) {
            $command->line('  · '.OpsDisplaySanitizer::field(RediHealthReport::hintMessage($hint, 'en'), '—', 300));
        }
    }
}
