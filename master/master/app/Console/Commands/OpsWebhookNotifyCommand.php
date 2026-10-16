<?php

namespace App\Console\Commands;

use App\Services\RediOpsWebhook;
use Illuminate\Console\Command;

class OpsWebhookNotifyCommand extends Command
{
    protected $signature = 'redi:ops-webhook
                            {--task= : 任务名（如 redi:renew-certificates）}
                            {--message= : 自定义说明}';

    protected $description = '向 REDI_OPS_WEBHOOK_URL / REDI_HEALTH_WEBHOOK_URL 发送运维告警';

    public function handle(): int
    {
        $task = trim((string) $this->option('task'));
        if ($task === '') {
            $task = 'redi:ops';
        }

        $message = trim((string) $this->option('message'));

        if (! RediOpsWebhook::notifyTaskFailure($task, $message)) {
            $this->warn('Webhook 未配置或发送失败（见 storage/logs/laravel.log）。');

            return self::FAILURE;
        }

        $this->info('Webhook 已发送。');

        return self::SUCCESS;
    }
}
