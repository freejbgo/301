<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Services\CertificateDeployService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NodeAgentController extends Controller
{
    protected function sanitizeAgentVersion($value): ?string
    {
        $version = preg_replace('/[^\w.\-]/', '', (string) $value);
        $version = substr($version, 0, 64);

        return $version === '' ? null : $version;
    }

    public function register(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $node->last_heartbeat_at = now();
        $node->agent_version = $this->sanitizeAgentVersion($request->input('version'));
        $node->save();

        return response()->json(['ok' => true, 'node_id' => $node->id]);
    }

    public function challengesSynced(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $domains = (array) $request->input('domains', []);
        $allowed = RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->pluck('domain_name')
            ->map(function ($d) {
                return strtolower(trim((string) $d, '.'));
            })
            ->flip();
        $now = now();
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain, '.'));
            if ($domain === '' || ! isset($allowed[$domain])) {
                continue;
            }
            RediAcmeChallengeNodeSync::updateOrCreate(
                ['node_id' => $node->id, 'domain_name' => $domain],
                ['synced_at' => $now]
            );
        }

        return response()->json(['ok' => true]);
    }

    public function revision()
    {
        $challenges = RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get(['domain_name', 'token', 'updated_at']);

        $certs = RediDomainCert::query()
            ->whereIn('status', [
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
                RediDomainCert::STATUS_ACTIVE,
            ])
            ->orderBy('id')
            ->get(['id', 'domain_name', 'updated_at']);

        $rules = DB::table('redi_admin_domain')
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'original_url', 'force_https', 'updated_at']);

        $revision = sha1(json_encode([
            'challenges' => $challenges,
            'certs' => $certs,
            'rules' => $rules,
        ]));

        return response()->json(['revision' => $revision]);
    }

    public function heartbeat(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $node->last_heartbeat_at = now();
        $node->agent_version = $this->sanitizeAgentVersion($request->input('version'));
        $node->save();

        return response()->json(['ok' => true]);
    }

    public function acmeChallenges()
    {
        $rows = RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->get(['domain_name', 'token', 'response_body']);

        return response()->json(['challenges' => $rows]);
    }

    public function certificates()
    {
        $certs = RediDomainCert::query()
            ->with('domain')
            ->whereIn('status', [
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
                RediDomainCert::STATUS_ACTIVE,
            ])
            ->whereHas('domain', function ($query) {
                $query->where('status', 1);
            })
            ->get();

        $payload = [];
        foreach ($certs as $cert) {
            $pem = $cert->getCertificatePem();
            $key = $cert->getPrivateKeyPem();
            if (! $pem || ! $key) {
                continue;
            }
            $payload[] = [
                'cert_id' => $cert->id,
                'domain' => $cert->domain_name,
                'certificate' => $pem,
                'private_key' => $key,
                'force_https' => (bool) optional($cert->domain)->force_https,
            ];
        }

        return response()->json(['certificates' => $payload]);
    }

    public function ack(Request $request, CertificateDeployService $deployService)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $certId = (int) $request->input('cert_id');
        $ok = (bool) $request->input('ok', true);
        $error = $request->input('error');

        $cert = RediDomainCert::find($certId);
        if (! $cert) {
            return response()->json(['message' => 'cert not found'], 404);
        }

        $hasDeployment = RediCertDeployment::query()
            ->where('cert_id', $cert->id)
            ->where('node_id', $node->id)
            ->exists();
        if (! $hasDeployment) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $deployService->markNodeAck($cert, $node, $ok, $error);

        return response()->json(['ok' => true]);
    }

    public function redirectRules()
    {
        $rows = DB::table('redi_admin_domain')
            ->where('status', 1)
            ->get(['original_url', 'force_https'])
            ->map(function ($row) {
                return [
                    'original_url' => $row->original_url,
                    'force_https' => (bool) $row->force_https,
                ];
            })
            ->values();

        return response()->json(['rules' => $rows]);
    }
}
