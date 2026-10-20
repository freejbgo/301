<?php

namespace App\Services;

use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Support\HostNameValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NodeAgentRevisionService
{
    /**
     * @return array{revision: string, challenges_revision: string, rules_revision: string, certificates_revision: string}
     */
    public function build(?RediNode $node = null): array
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
            $certsQuery->whereHas('deployments', function ($query) use ($node) {
                if ($node !== null) {
                    $query->where('node_id', $node->id);
                }
            });
            if ($node !== null) {
                $certsQuery->with([
                    'deployments' => function ($query) use ($node) {
                        $query->where('node_id', $node->id);
                    },
                ]);
            }
        }

        $certs = $certsQuery
            ->with('domain')
            ->orderBy('id')
            ->get(['id', 'domain_id', 'domain_name', 'status', 'certificate', 'private_key', 'last_error', 'updated_at'])
            ->each(function ($row) {
                DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($row, false);
            })
            ->filter(function ($row) {
                return CertificateDeployService::certIsNodePullable($row);
            })
            ->map(function ($row) use ($node) {
                $payload = [
                    'id' => (int) $row->id,
                    'domain_id' => (int) $row->domain_id,
                    'domain_name' => HostNameValidator::toCanonicalAscii((string) $row->domain_name),
                    'force_https' => (bool) optional($row->domain)->force_https,
                    'updated_at' => $row->updated_at,
                    'credentials' => $row->credentialCiphertextFingerprint(),
                ];
                if ($node !== null) {
                    $deployment = $row->deployments->first();
                    $payload['cert_status'] = (string) $row->status;
                    $payload['cert_last_error'] = sha1((string) $row->last_error);
                    $payload['deployment_status'] = (string) optional($deployment)->status;
                    $payload['deployment_error'] = sha1((string) optional($deployment)->last_error);
                }

                return (object) $payload;
            })
            ->values();

        $rules = DB::table('redi_admin_domain')
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'original_url', 'force_https', 'updated_at'])
            ->map(function ($row) {
                $host = DomainRecordNormalizer::acmeHostKey((string) $row->original_url);
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
