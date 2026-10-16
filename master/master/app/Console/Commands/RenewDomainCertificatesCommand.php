<?php

namespace App\Console\Commands;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediDomainCert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewDomainCertificatesCommand extends Command
{
    protected $signature = 'redi:renew-certificates
                            {--days=25 : 到期多少天内触发续期排队}
                            {--dry-run : 只列出将续期的域名，不入队}';

    protected $description = '续期即将过期的域名证书（HTTP-01）';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $deadline = now()->addDays($days);

        $validatingStuckHours = max(1, (int) config('redi.renew_validating_stuck_hours', 3));

        $certs = RediDomainCert::query()
            ->renewalDue($deadline, $validatingStuckHours)
            ->whereHas('domain', function ($query) {
                $query->where('status', 1);
            })
            ->orderBy('expires_at')
            ->get();

        $queued = 0;
        $i = 0;
        foreach ($certs as $cert) {
            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] %s expires %s status=%s',
                    $cert->domain_name,
                    $cert->expires_at->format('Y-m-d H:i'),
                    $cert->status
                ));
                $queued++;
                continue;
            }

            IssueDomainCertificateJob::dispatch((int) $cert->domain_id)
                ->delay(now()->addSeconds($i * 20));
            $this->info('Queued renew for '.$cert->domain_name);
            $i++;
            $queued++;
        }

        $summary = sprintf(
            'redi:renew-certificates %s: %d cert(s) within %d days',
            $dryRun ? 'dry-run' : 'queued',
            $queued,
            $days
        );
        Log::info($summary);
        if ($queued === 0 && ! $dryRun) {
            $this->info('没有需要续期的证书。');
        } else {
            $this->info($summary);
        }

        return self::SUCCESS;
    }
}
