<?php

namespace App\Jobs;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Services\Acme\CertificateIssuer;
use App\Services\AcmeChallengeCleanup;
use App\Services\DomainRecordNormalizer;
use App\Services\CnamePrecheckService;
use App\Services\TenantEdgeService;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use App\Support\RediCertLastError;
use App\Support\RediErrorMessage;
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
        // WithoutOverlapping 使用 CACHE_DRIVER；多台 queue:work 时请用 redis 等共享缓存。
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
            RediDomainCert::query()->where('domain_id', $this->domainId)->delete();

            return;
        }

        if ((int) $domain->status !== 1) {
            return;
        }

        $publishableHost = DomainRecordNormalizer::originalUrlIfValid((string) $domain->original_url);

        $cert = RediDomainCert::firstOrCreate(
            ['domain_id' => $domain->id],
            [
                'domain_name' => $publishableHost,
                'status' => RediDomainCert::STATUS_NONE,
            ]
        );
        $cert->admin_id = (int) $domain->admin_id;

        if ($publishableHost === '') {
            $cert->domain_name = '';
            $cert->status = RediDomainCert::STATUS_FAILED;
            RediCertLastError::apply($cert, 'cert.invalid_hostname');
            $cert->save();

            return;
        }

        $cert->domain_name = $publishableHost;
        $cert->save();

        $canonicalHost = $publishableHost;

        TenantEdgeService::ensureForAdmin((int) $domain->admin_id);
        $edge = \App\Models\RediTenantEdge::where('admin_id', $domain->admin_id)->first();
        $edgeFqdn = $edge ? $edge->edge_fqdn : '';

        if ($edgeFqdn === '') {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            RediCertLastError::apply($cert, 'cert.platform_edge_missing');
            $cert->save();

            return;
        }

        $customerHost = $canonicalHost;
        if (! HostNameValidator::isNodePublishableHost($edgeFqdn)) {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            RediCertLastError::apply($cert, 'cert.invalid_tenant_edge');
            $cert->save();

            return;
        }

        $edgeHost = HostNameValidator::toCanonicalAscii($edgeFqdn);

        if (! CnamePrecheckService::pointsToEdge($customerHost, $edgeHost)) {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            RediCertLastError::apply($cert, 'cert.cname_not_pointing', [
                'target' => OpsDisplaySanitizer::field($edgeHost, $edgeHost, 253),
            ]);
            $cert->save();

            return;
        }

        $previousStatus = $cert->status;
        $stillValid = $cert->expires_at && $cert->expires_at->isFuture()
            && $cert->getCertificatePem() && $cert->getPrivateKeyPem();

        try {
            $issuer->issue($cert);
        } catch (Throwable $e) {
            AcmeChallengeCleanup::purgeForHosts([
                (string) $domain->original_url,
                $customerHost,
                (string) $cert->domain_name,
            ]);
            $keptOldCert = $stillValid && in_array($previousStatus, [
                RediDomainCert::STATUS_ACTIVE,
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
            ], true);
            if ($keptOldCert) {
                $cert->status = $previousStatus;
                RediCertLastError::applyRaw(
                    $cert,
                    RediErrorMessage::get('cert.renew_failed_keep_old')
                        .OpsDisplaySanitizer::logSnippet($e->getMessage(), 1500),
                    'cert.renew_failed_keep_old'
                );
            } else {
                $cert->status = RediDomainCert::STATUS_FAILED;
                RediCertLastError::applyRaw(
                    $cert,
                    OpsDisplaySanitizer::logSnippet($e->getMessage(), 2000)
                );
            }
            $cert->save();
            if (! $keptOldCert) {
                throw $e;
            }

            return;
        }
    }
}
