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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IssueDomainCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $domainId;

    /** 等锁会增加 attempts；在 retryUntil 之前不会因此失败。 */
    public int $tries = 100;

    /** Let's Encrypt 或探测失败只重试有限次，等锁不计入。 */
    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $backoff = 120;

    /**
     * 批量排队时等锁不占失败次数，直到入队后 24 小时。
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('redi-acme-le-global'))
                ->releaseAfter(25)
                ->expireAfter(400),
            (new WithoutOverlapping('redi-acme-domain-'.$this->domainId))
                ->releaseAfter(25)
                ->expireAfter(400),
        ];
    }

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

        if ((int) $domain->status !== 1) {
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

        if ($edgeFqdn === '') {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            $cert->last_error = '平台主域名或租户 edge 未配置';
            $cert->save();

            return;
        }

        if (! CnamePrecheckService::pointsToEdge($domain->original_url, $edgeFqdn)) {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            $cert->last_error = 'CNAME 尚未指向 '.$edgeFqdn;
            $cert->save();

            return;
        }

        $previousStatus = $cert->status;
        $stillValid = $cert->expires_at && $cert->expires_at->isFuture()
            && $cert->getCertificatePem() && $cert->getPrivateKeyPem();

        try {
            $issuer->issue($cert);
        } catch (Throwable $e) {
            if ($stillValid && in_array($previousStatus, [
                RediDomainCert::STATUS_ACTIVE,
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
            ], true)) {
                $cert->status = $previousStatus;
                $cert->last_error = '续期/重签失败（节点仍使用旧证书）：'.$e->getMessage();
            } else {
                $cert->status = RediDomainCert::STATUS_FAILED;
                $cert->last_error = $e->getMessage();
            }
            $cert->save();
            throw $e;
        }
    }
}
