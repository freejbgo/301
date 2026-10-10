<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RediAcmeChallenge;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Services\CertificateDeployService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NodeAgentController extends Controller
{
    public function heartbeat(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $node->last_heartbeat_at = now();
        $node->agent_version = $request->input('version');
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
            ->whereIn('status', [
                RediDomainCert::STATUS_ISSUED,
                RediDomainCert::STATUS_DEPLOYING,
                RediDomainCert::STATUS_ACTIVE,
            ])
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

        $deployService->markNodeAck($cert, $node, $ok, $error);

        return response()->json(['ok' => true]);
    }

    public function redirectRules()
    {
        $rows = DB::table('redi_admin_domain')
            ->where('status', 1)
            ->get(['original_url', 'skip_url', 'force_https']);

        return response()->json(['rules' => $rows]);
    }
}
