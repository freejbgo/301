<?php

namespace App\Services;

use App\Models\RediDomainCert;
use App\Support\HostNameValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            ->map(function ($row) {
                $row->domain_name = HostNameValidator::toCanonicalAscii((string) $row->domain_name);

                return $row;
            })
            ->filter(function ($row) {
                return AcmeChallengeQuery::nodePublishableRow($row->domain_name, $row->token, $row->response_body);
            })
            ->values();

        $certs = RediDomainCert::query()
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
            })
            ->orderBy('id')
            ->get(['id', 'domain_name', 'updated_at'])
            ->map(function ($row) {
                $row->domain_name = HostNameValidator::toCanonicalAscii((string) $row->domain_name);

                return $row;
            })
            ->filter(function ($row) {
                return $row->domain_name !== '' && HostNameValidator::isValidHostLabel($row->domain_name);
            })
            ->values();

        $rules = DB::table('redi_admin_domain')
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'original_url', 'force_https', 'updated_at'])
            ->map(function ($row) {
                $row->original_url = HostNameValidator::toCanonicalAscii((string) $row->original_url);

                return $row;
            })
            ->filter(function ($row) {
                return $row->original_url !== '' && HostNameValidator::isValidHostLabel($row->original_url);
            })
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
