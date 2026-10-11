<?php

namespace App\Services\Acme;

use Afosto\Acme\Client;
use App\Models\RediAcmeChallenge;
use App\Models\RediDomainCert;
use App\Models\RediPlatformDns;
use App\Services\CertificateDeployService;
use App\Services\Acme\Http01PropagationWaiter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use RuntimeException;

class CertificateIssuer
{
    public function issue(RediDomainCert $cert): void
    {
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

        $domain = strtolower(trim($cert->domain_name));
        $cert->status = RediDomainCert::STATUS_VALIDATING;
        $cert->last_error = null;
        $cert->save();

        $order = $client->createOrder([$domain]);
        $authorizations = $client->authorize($order);

        foreach ($authorizations as $authorization) {
            if (strtolower($authorization->getDomain()) !== $domain) {
                continue;
            }

            $file = $authorization->getFile();
            $token = basename($file->getFilename());
            $body = $file->getContents();
            DB::table('redi_acme_challenge_node_sync')->where('domain_name', $domain)->delete();
            RediAcmeChallenge::upsertForDomain(
                $domain,
                $token,
                $body,
                Carbon::now()->addHours(2)
            );

            app(Http01PropagationWaiter::class)->wait($domain, $token, $body);

            $client->validate($authorization->getHttpChallenge(), 15);
        }

        if (! $client->isReady($order)) {
            throw new RuntimeException('ACME 验证未完成，请确认 CNAME 已生效且节点可访问 HTTP-01');
        }

        $certificate = $client->getCertificate($order);
        $cert->setCertificatePem($certificate->getCertificate());
        $cert->setPrivateKeyPem($certificate->getPrivateKey());
        $cert->expires_at = $this->parseCertExpiry($certificate->getCertificate());
        $cert->status = RediDomainCert::STATUS_ISSUED;
        $cert->save();

        RediAcmeChallenge::where('domain_name', $domain)->delete();

        app(CertificateDeployService::class)->queueDeploy($cert);
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
