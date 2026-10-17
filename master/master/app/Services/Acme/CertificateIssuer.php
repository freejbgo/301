<?php

namespace App\Services\Acme;

use Afosto\Acme\Client;
use App\Models\RediAcmeChallenge;
use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Models\RediPlatformDns;
use App\Services\AcmeChallengeCleanup;
use App\Services\AcmeChallengeQuery;
use App\Services\CertificateDeployService;
use App\Services\Acme\Http01PropagationWaiter;
use App\Support\AcmeHttp01Token;
use App\Services\DomainRecordNormalizer;
use App\Support\HostNameValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use RuntimeException;

class CertificateIssuer
{
    public function issue(RediDomainCert $cert): void
    {
        $this->assertDomainIssuable($cert);

        if (! class_exists(Client::class)) {
            throw new RuntimeException('请先在主控执行 composer require afosto/yaac');
        }

        $platform = RediPlatformDns::instance();
        $email = trim($platform->acme_contact_email ?? '');
        if ($email === '') {
            throw new RuntimeException('请在 DNS 与节点池 配置 ACME 联系邮箱');
        }

        $storagePath = storage_path('app/acme');
        if (! File::isDirectory($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        $client = new Client([
            'username' => $email,
            'fs' => new Filesystem(new Local($storagePath)),
            'mode' => $platform->acme_staging ? Client::MODE_STAGING : Client::MODE_LIVE,
        ]);

        $domain = DomainRecordNormalizer::acmeHostKey((string) $cert->domain_name);
        if ($domain === '') {
            throw new RuntimeException('域名主机名无效，无法签发证书');
        }
        if ($cert->domain_name !== $domain) {
            $cert->domain_name = $domain;
            $cert->save();
        }
        $cert->status = RediDomainCert::STATUS_VALIDATING;
        $cert->last_error = null;
        $cert->save();

        try {
            $this->runAcmeIssue($client, $cert, $domain);
        } catch (\Throwable $e) {
            AcmeChallengeCleanup::purgeForHosts([$domain]);
            throw $e;
        }
    }

    protected function runAcmeIssue(Client $client, RediDomainCert $cert, string $domain): void
    {
        $order = $client->createOrder([$domain]);
        $authorizations = $client->authorize($order);

        $authorized = false;
        foreach ($authorizations as $authorization) {
            $authDomain = HostNameValidator::toCanonicalAscii(strtolower($authorization->getDomain()));
            if ($authDomain === '' || $authDomain !== $domain) {
                continue;
            }
            $authorized = true;

            $file = $authorization->getFile();
            $token = AcmeHttp01Token::normalize(basename($file->getFilename()));
            $body = trim($file->getContents());
            if (! AcmeChallengeQuery::http01ContentValid($token, $body)) {
                throw new RuntimeException('ACME HTTP-01 响应体无效');
            }
            AcmeChallengeCleanup::purgeForHosts([$domain]);
            RediAcmeChallenge::upsertForDomain(
                $domain,
                $token,
                $body,
                Carbon::now()->addHours(2)
            );

            app(Http01PropagationWaiter::class)->wait($domain, $token, $body);

            $client->validate($authorization->getHttpChallenge(), 15);
        }

        if (! $authorized) {
            throw new RuntimeException('ACME 未返回域名 '.$domain.' 的 HTTP-01 授权');
        }

        if (! $client->isReady($order)) {
            throw new RuntimeException('ACME 验证未完成，请确认 CNAME 已生效且节点可访问 HTTP-01');
        }

        $certificate = $client->getCertificate($order);
        $cert->setCertificatePem($certificate->getCertificate());
        $cert->setPrivateKeyPem($certificate->getPrivateKey());
        $cert->expires_at = $this->parseCertExpiry($certificate->getCertificate());
        $this->assertDomainIssuable($cert);
        $cert->status = RediDomainCert::STATUS_ISSUED;
        $cert->save();

        AcmeChallengeCleanup::purgeForHosts([$domain]);

        app(CertificateDeployService::class)->queueDeploy($cert);
    }

    protected function assertDomainIssuable(RediDomainCert $cert): void
    {
        $domain = RediAdminDomain::query()->find($cert->domain_id);
        if (! $domain) {
            throw new RuntimeException('域名已删除，已中止证书签发');
        }
        if ((int) $domain->status !== 1) {
            throw new RuntimeException('域名已停用，已中止证书签发');
        }
        $cert->setRelation('domain', $domain);
        if (! DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert)
            || ! DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
            throw new RuntimeException('域名主机名无效，无法签发证书');
        }
    }

    protected function parseCertExpiry(string $pemBundle): ?Carbon
    {
        $parts = preg_split('/-----END CERTIFICATE-----/', $pemBundle);
        $leaf = trim($parts[0] ?? '')."\n-----END CERTIFICATE-----\n";
        $info = @openssl_x509_parse($leaf);
        if (! is_array($info) || empty($info['validTo_time_t'])) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $info['validTo_time_t']);
    }
}
