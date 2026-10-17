<?php

namespace App\Jobs;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Services\Acme\CertificateIssuer;
use App\Services\AcmeChallengeCleanup;
use App\Services\CnamePrecheckService;
use App\Services\TenantEdgeService;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
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

        if (! HostNameValidator::isNodePublishableHost((string) $domain->original_url)) {
            $canonicalHost = HostNameValidator::toCanonicalAscii((string) $domain->original_url);

            $cert = RediDomainCert::firstOrCreate(
                ['domain_id' => $domain->id],
                [
                    'domain_name' => $canonicalHost,
                    'status' => RediDomainCert::STATUS_NONE,
                ]
            );

            $cert->domain_name = $canonicalHost;
            $cert->admin_id = (int) $domain->admin_id;
            $cert->status = RediDomainCert::STATUS_FAILED;
            $cert->last_error = '域名主机名无效，无法签发证书';
            $cert->save();

            return;
        }

        $canonicalHost = HostNameValidator::toCanonicalAscii((string) $domain->original_url);

        $cert = RediDomainCert::firstOrCreate(
            ['domain_id' => $domain->id],
            [
                'domain_name' => $canonicalHost,
                'status' => RediDomainCert::STATUS_NONE,
            ]
        );

        $cert->domain_name = $canonicalHost;
        $cert->admin_id = (int) $domain->admin_id;
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

        $customerHost = $canonicalHost;
        if (! HostNameValidator::isNodePublishableHost($edgeFqdn)) {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            $cert->last_error = '租户 edge 主机名无效，请检查平台主域名与租户 token';
            $cert->save();

            return;
        }

        $edgeHost = HostNameValidator::toCanonicalAscii($edgeFqdn);

        if (! CnamePrecheckService::pointsToEdge($customerHost, $edgeHost)) {
            $cert->status = RediDomainCert::STATUS_PENDING_DNS;
            $cert->last_error = 'CNAME 尚未指向 '.OpsDisplaySanitizer::field($edgeHost, $edgeHost, 253);
            $cert->save();

            return;
        }

        $previousStatus = $cert->status;
        $stillValid = $cert->expires_at && $cert->expires_at->isFuture()
            && $cert->getCertificatePem() && $cert->getPrivateKeyPem();

        try {
            $issuer->issue($cert);
        } catch (Throwable $e) {
            AcmeChallengeCleanup::purgeForHosts([$customerHost, (string) $cert->domain_name]);
            if ($stillValid && in_array($previousStatus, [
                RediDomainCert::STATUS_ACTIVE,
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
            ], true)) {
                $cert->status = $previousStatus;
                $cert->last_error = '续期/重签失败（节点仍使用旧证书）：'
                    .OpsDisplaySanitizer::logSnippet($e->getMessage(), 1500);
            } else {
                $cert->status = RediDomainCert::STATUS_FAILED;
                $cert->last_error = OpsDisplaySanitizer::logSnippet($e->getMessage(), 2000);
            }
            $cert->save();
            throw $e;
        }
    }
}
