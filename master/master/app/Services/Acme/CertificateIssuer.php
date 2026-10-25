<?php

namespace App\Services\Acme;

use App\Support\RediErrorMessage;
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
            throw new RuntimeException(RediErrorMessage::get('acme.yaac_missing'));
        }

        $platform = RediPlatformDns::instance();
        $email = trim($platform->acme_contact_email ?? '');
        if ($email === '') {
            throw new RuntimeException(RediErrorMessage::get('acme.contact_email_missing'));
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
            throw new RuntimeException(RediErrorMessage::get('cert.invalid_hostname'));
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
                throw new RuntimeException(RediErrorMessage::get('acme.http01_body_invalid'));
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
            throw new RuntimeException(RediErrorMessage::get('acme.http01_auth_missing', ['domain' => $domain]));
        }

        if (! $client->isReady($order)) {
            throw new RuntimeException(RediErrorMessage::get('acme.http01_not_ready'));
        }

        $certificate = $client->getCertificate($order);
        $cert->setCertificatePem($certificate->getCertificate());
        $cert->setPrivateKeyPem($certificate->getPrivateKey());
        $cert->expires_at = $this->parseCertExpiry($certificate->getCertificate());
        $this->assertDomainIssuable($cert);
        $cert->status = RediDomainCert::STATUS_ISSUED;
        $cert->save();

        AcmeChallengeCleanup::purgeForHosts([$domain]);

        if (! app(CertificateDeployService::class)->queueDeploy($cert)) {
            $cert->refresh();
        }
    }

    protected function assertDomainIssuable(RediDomainCert $cert): void
    {
        $domain = RediAdminDomain::query()->find($cert->domain_id);
        if (! $domain) {
            throw new RuntimeException(RediErrorMessage::get('acme.domain_deleted'));
        }
        if ((int) $domain->status !== 1) {
            throw new RuntimeException(RediErrorMessage::get('acme.domain_disabled'));
        }
        $cert->setRelation('domain', $domain);
        $fresh = CertificateDeployService::freshCertWithPersistedRealign($cert);
        if (! $fresh || ! DomainRecordNormalizer::certMatchesAdminDomain($fresh)) {
            throw new RuntimeException(RediErrorMessage::get('cert.invalid_hostname'));
        }
        $cert->refresh();
        $cert->loadMissing('domain');
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
