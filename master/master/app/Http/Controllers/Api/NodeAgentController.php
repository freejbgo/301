<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RediAcmeChallengeNodeSync;
use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Services\AcmeChallengeQuery;
use App\Services\CertificateDeployService;
use App\Services\NodeAgentRevisionService;
use App\Support\HostNameValidator;
use App\Support\RediAgentVersionGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NodeAgentController extends Controller
{
    public function register(Request $request, CertificateDeployService $deployService)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $version = RediAgentVersionGate::sanitize($request->input('version'));
        $node->last_heartbeat_at = now();
        $node->agent_version = $version;
        $node->save();

        $deployService->syncDeploymentsForNode($node);

        return response()->json(['ok' => true, 'node_id' => $node->id]);
    }

    public function challengesSynced(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $domains = array_slice((array) $request->input('domains', []), 0, 200);
        $allowed = AcmeChallengeQuery::ready()
            ->pluck('domain_name')
            ->map(function ($d) {
                return HostNameValidator::toCanonicalAscii((string) $d);
            })
            ->flip();
        $now = now();
        $synced = [];
        foreach ($domains as $raw) {
            $domain = HostNameValidator::toCanonicalAscii((string) $raw);
            if ($domain === '') {
                continue;
            }
            if (! HostNameValidator::isValidHostLabel($domain)) {
                return response()->json(['message' => 'invalid domain', 'domain' => (string) $raw], 422);
            }
            if (! $allowed->has($domain)) {
                continue;
            }
            RediAcmeChallengeNodeSync::updateOrCreate(
                ['node_id' => $node->id, 'domain_name' => $domain],
                ['synced_at' => $now]
            );
            $synced[] = $domain;
        }

        return response()->json([
            'ok' => true,
            'synced' => $synced,
            'synced_count' => count($synced),
            'ignored_count' => max(0, count($domains) - count($synced)),
        ]);
    }

    public function revision(NodeAgentRevisionService $revisions)
    {
        return response()->json($revisions->build());
    }

    public function heartbeat(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $version = RediAgentVersionGate::sanitize($request->input('version'));
        $node->last_heartbeat_at = now();
        $node->agent_version = $version;
        $node->save();

        return response()->json(['ok' => true]);
    }

    public function acmeChallenges()
    {
        $rows = AcmeChallengeQuery::ready()
            ->get(['domain_name', 'token', 'response_body']);

        return response()->json(['challenges' => $rows]);
    }

    public function certificates(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');

        $certs = RediDomainCert::query()
            ->with('domain')
            ->whereHas('deployments', function ($query) use ($node) {
                $query->where('node_id', $node->id);
            })
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
            ->get();

        $payload = [];
        foreach ($certs as $cert) {
            $pem = $cert->getCertificatePem();
            $key = $cert->getPrivateKeyPem();
            if (! $pem || ! $key) {
                if ($cert->certificate || $cert->private_key) {
                    $cert->flagCredentialUnreadableIfNeeded();
                }
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

        if ($certId < 1) {
            return response()->json(['message' => 'invalid cert_id'], 422);
        }

        $cert = RediDomainCert::find($certId);
        if (! $cert) {
            return response()->json(['message' => 'cert not found'], 404);
        }

        $hasDeployment = RediCertDeployment::query()
            ->where('cert_id', $certId)
            ->where('node_id', $node->id)
            ->exists();
        if (! $hasDeployment) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $error = is_string($error) ? mb_substr($error, 0, 2000) : null;

        if (! $deployService->markNodeAck($cert, $node, $ok, $error)) {
            return response()->json(['message' => 'ack not applied'], 409);
        }

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
