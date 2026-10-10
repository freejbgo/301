<?php

namespace App\Console\Commands;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediDomainCert;
use Illuminate\Console\Command;

class RenewDomainCertificatesCommand extends Command
{
    protected $signature = 'redi:renew-certificates';

    protected $description = '续期即将过期的域名证书（HTTP-01）';

    public function handle(): int
    {
        $certs = RediDomainCert::query()
            ->whereIn('status', [RediDomainCert::STATUS_ACTIVE, RediDomainCert::STATUS_ISSUED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(25))
            ->get();

        foreach ($certs as $cert) {
            IssueDomainCertificateJob::dispatch((int) $cert->domain_id);
            $this->info('Queued renew for '.$cert->domain_name);
        }

        return 0;
    }
}
