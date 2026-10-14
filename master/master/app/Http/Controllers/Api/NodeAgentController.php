<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Services\CertificateDeployService;
use App\Services\NodeAgentRevisionService;
use App\Support\HostNameValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NodeAgentController extends Controller
{
    protected function sanitizeAgentVersion($value): ?string
    {
        $version = preg_replace('/[^\w.\-]/', '', (string) $value);
        $version = substr($version, 0, 32);

        return $version === '' ? null : $version;
    }

    protected function rejectIfAgentTooOld(?string $version)
    {
        $min = trim((string) config('redi.min_agent_version', ''));
        if ($min === '') {
            return null;
        }

        if ($version === null || $version === '') {
            return response()->json([
                'message' => '节点 Agent 必须上报 version 字段，请升级 redi-multi 后重试',
                'min_version' => $min,
            ], 426);
        }

        if (version_compare($version, $min, '<')) {
            return response()->json([
                'message' => '节点 Agent 版本过低，请升级 redi-multi 后重试',
                'min_version' => $min,
            ], 426);
        }

        return null;
    }

    public function register(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $version = $this->sanitizeAgentVersion($request->input('version'));
        if ($reject = $this->rejectIfAgentTooOld($version)) {
            return $reject;
        }
        $node->last_heartbeat_at = now();
        $node->agent_version = $version;
        $node->save();

        return response()->json(['ok' => true, 'node_id' => $node->id]);
    }

    public function challengesSynced(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $domains = array_slice((array) $request->input('domains', []), 0, 200);
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
            if ($domain === '' || ! HostNameValidator::isValidHostLabel($domain) || ! $allowed->has($domain)) {
                continue;
            }
            RediAcmeChallengeNodeSync::updateOrCreate(
                ['node_id' => $node->id, 'domain_name' => $domain],
                ['synced_at' => $now]
            );
        }

        return response()->json(['ok' => true]);
    }

    public function revision(NodeAgentRevisionService $revisions)
    {
        return response()->json($revisions->build());
    }

    public function heartbeat(Request $request)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $version = $this->sanitizeAgentVersion($request->input('version'));
        if ($reject = $this->rejectIfAgentTooOld($version)) {
            return $reject;
        }
        $node->last_heartbeat_at = now();
        $node->agent_version = $version;
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

        $pendingDeploy = RediCertDeployment::query()
            ->where('cert_id', $certId)
            ->where('node_id', $node->id)
            ->exists();
        if (! $pendingDeploy) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $error = is_string($error) ? mb_substr($error, 0, 2000) : null;

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
