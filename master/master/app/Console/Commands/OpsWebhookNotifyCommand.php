<?php

namespace App\Console\Commands;

use App\Services\RediOpsWebhook;
use App\Support\RediErrorMessage;
use Illuminate\Console\Command;

class OpsWebhookNotifyCommand extends Command
{
    protected $signature = 'redi:ops-webhook
                            {--task= : Task name (e.g. redi:renew-certificates)}
                            {--message= : Custom message body}';

    protected $description = 'POST ops alert to REDI_OPS_WEBHOOK_URL / REDI_HEALTH_WEBHOOK_URL';

    public function handle(): int
    {
        $task = trim((string) $this->option('task'));
        if ($task === '') {
            $task = 'redi:ops';
        }

        $message = trim((string) $this->option('message'));

        if (! RediOpsWebhook::notifyTaskFailure($task, $message)) {
            $this->warn(RediErrorMessage::get('cli.ops_webhook_failed'));

            return self::FAILURE;
        }

        $this->info(RediErrorMessage::get('cli.ops_webhook_sent'));

        return self::SUCCESS;
    }
}
