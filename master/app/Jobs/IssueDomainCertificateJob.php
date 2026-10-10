<?php

namespace App\Jobs;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Services\Acme\CertificateIssuer;
use App\Services\CnamePrecheckService;
use App\Services\TenantEdgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IssueDomainCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $domainId;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(int $domainId)
    {
        $this->domainId = $domainId;
    }

    public function handle(CertificateIssuer $issuer): void
    {
        $domain = RediAdminDomain::query()->find($this->domainId);
        if (! $domain) {
            return;
        }

        $cert = RediDomainCert::firstOrCreate(
            ['domain_id' => $domain->id],
            [
                'admin_id' => $domain->admin_id,
                'domain_name' => $domain->original_url,
                'status' => RediDomainCert::STATUS_NONE,
            ]
        );

        $cert->domain_name = $domain->original_url;
        $cert->admin_id = $domain->admin_id;
        $cert->save();

        TenantEdgeService::ensureForAdmin((int) $domain->admin_id);
        $edge = \App\Models\RediTenantEdge::where('admin_id', $domain->admin_id)->first();
        $edgeFqdn = $edge ? $edge->edge_fqdn : '';

        if ($edgeFqdn && ! CnamePrecheckService::pointsToEdge($domain->original_url, $edgeFqdn)) {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            $cert->last_error = 'CNAME 尚未指向 '.$edgeFqdn;
            $cert->save();

            return;
        }

        try {
            $issuer->issue($cert);
        } catch (Throwable $e) {
            $cert->status = RediDomainCert::STATUS_FAILED;
            $cert->last_error = $e->getMessage();
            $cert->save();
            throw $e;
        }
    }
}
