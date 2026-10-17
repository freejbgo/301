<?php

namespace App\Services;

use App\Models\RediDomainCert;
use App\Support\HostNameValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NodeAgentRevisionService
{
    /**
     * @return array{revision: string, challenges_revision: string, rules_revision: string, certificates_revision: string}
     */
    public function build(): array
    {
        $challenges = AcmeChallengeQuery::ready()
            ->orderBy('id')
            ->get(['domain_name', 'token', 'response_body', 'updated_at'])
            ->filter(function ($row) {
                return AcmeChallengeQuery::nodePublishableRow($row->domain_name, $row->token, $row->response_body);
            })
            ->map(function ($row) {
                $host = DomainRecordNormalizer::acmeHostKey((string) $row->domain_name);
                if ($host === '') {
                    return null;
                }
                $row->domain_name = $host;

                return $row;
            })
            ->filter()
            ->values();

        $certsQuery = RediDomainCert::query()
            ->where(function ($query) {
                $query->whereIn('status', [
                    RediDomainCert::STATUS_ISSUED,
                    RediDomainCert::STATUS_DEPLOYING,
                    RediDomainCert::STATUS_ACTIVE,
                ])->orWhere(function ($inner) {
                    $inner->where('status', RediDomainCert::STATUS_VALIDATING)
                        ->whereNotNull('certificate')
                        ->where('certificate', '!=', '');
                });
            })
            ->whereHas('domain', function ($query) {
                $query->where('status', 1);
            });

        if (Schema::hasTable('redi_cert_deployments')) {
            $certsQuery->whereHas('deployments');
        }

        $certs = $certsQuery
            ->with('domain')
            ->orderBy('id')
            ->get(['id', 'domain_id', 'domain_name', 'status', 'certificate', 'private_key', 'updated_at'])
            ->each(function ($row) {
                DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($row, false);
            })
            ->filter(function ($row) {
                return CertificateDeployService::certIsNodePullable($row);
            })
            ->map(function ($row) {
                return (object) [
                    'id' => (int) $row->id,
                    'domain_id' => (int) $row->domain_id,
                    'domain_name' => HostNameValidator::toCanonicalAscii((string) $row->domain_name),
                    'updated_at' => $row->updated_at,
                ];
            })
            ->values();

        $rules = DB::table('redi_admin_domain')
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'original_url', 'force_https', 'updated_at'])
            ->map(function ($row) {
                $host = DomainRecordNormalizer::originalUrlIfValid((string) $row->original_url);
                if ($host === '') {
                    return null;
                }
                $row->original_url = $host;

                return $row;
            })
            ->filter()
            ->values();

        $challengesRevision = $this->hashPayload($challenges);
        $rulesRevision = $this->hashPayload($rules);
        $certificatesRevision = $this->hashPayload($certs);

        $combined = sha1(json_encode([
            'challenges' => $challengesRevision,
            'rules' => $rulesRevision,
            'certificates' => $certificatesRevision,
        ]));

        return [
            'revision' => $combined,
            'challenges_revision' => $challengesRevision,
            'rules_revision' => $rulesRevision,
            'certificates_revision' => $certificatesRevision,
        ];
    }

    protected function hashPayload(Collection $rows): string
    {
        return sha1(json_encode($rows));
    }
}
