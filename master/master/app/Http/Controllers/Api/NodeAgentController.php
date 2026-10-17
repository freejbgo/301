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
use App\Support\OpsDisplaySanitizer;
use App\Support\RediAgentVersionGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NodeAgentController extends Controller
{
    public function register(Request $request, CertificateDeployService $deployService)
    {
        /** @var RediNode $node */
        $node = $request->attributes->get('redi_node');
        $this->persistAgentVersionIfPresent($request, $node);
        $node->last_heartbeat_at = now();
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
            ->get(['domain_name', 'token', 'response_body'])
            ->filter(function ($row) {
                return AcmeChallengeQuery::nodeReadyRow($row->domain_name, $row->token, $row->response_body);
            })
            ->mapWithKeys(function ($row) {
                $domain = HostNameValidator::toCanonicalAscii((string) $row->domain_name);

                return [$domain => true];
            });
        $now = now();
        $synced = [];
        $ignored = 0;
        foreach ($domains as $raw) {
            $domain = HostNameValidator::toCanonicalAscii((string) $raw);
            if ($domain === '') {
                continue;
            }
            if (strlen($domain) > 253 || ! HostNameValidator::isValidHostLabel($domain)) {
                return response()->json([
                    'message' => 'invalid domain',
                    'domain' => OpsDisplaySanitizer::field((string) $raw, '', 253),
                ], 422);
            }
            if (! $allowed->has($domain)) {
                $ignored++;
                continue;
            }
            RediAcmeChallengeNodeSync::updateOrCreate(
                ['node_id' => $node->id, 'domain_name' => $domain],
                ['synced_at' => $now]
            );
            $synced[] = $domain;
        }

        if ($synced !== []) {
            $node->last_heartbeat_at = $now;
            $node->save();
        }

        return response()->json([
            'ok' => true,
            'synced' => $synced,
            'synced_count' => count($synced),
            'ignored_count' => $ignored,
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
        $this->persistAgentVersionIfPresent($request, $node);
        $node->last_heartbeat_at = now();
        $node->save();

        return response()->json(['ok' => true]);
    }

    protected function persistAgentVersionIfPresent(Request $request, RediNode $node): void
    {
        $input = $request->all();
        if (! array_key_exists('version', $input)) {
            return;
        }
        $version = RediAgentVersionGate::resolveFromInput($input);
        if ($version !== null) {
            $node->agent_version = $version;
        }
    }

    public function acmeChallenges()
    {
        $rows = AcmeChallengeQuery::ready()
            ->get(['domain_name', 'token', 'response_body'])
            ->map(function ($row) {
                return [
                    'domain_name' => HostNameValidator::toCanonicalAscii((string) $row->domain_name),
                    'token' => $row->token,
                    'response_body' => $row->response_body,
                ];
            })
            ->filter(function ($row) {
                return AcmeChallengeQuery::nodeReadyRow(
                    $row['domain_name'] ?? null,
                    $row['token'] ?? null,
                    $row['response_body'] ?? null
                );
            })
            ->values();

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
            $host = HostNameValidator::toCanonicalAscii((string) $cert->domain_name);
            if ($host === '' || ! HostNameValidator::isValidHostLabel($host)) {
                continue;
            }
            $payload[] = [
                'cert_id' => $cert->id,
                'domain' => $host,
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

        $error = is_string($error) ? OpsDisplaySanitizer::logSnippet($error, 2000) : null;
        if ($error === '') {
            $error = null;
        }

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
                    'original_url' => HostNameValidator::toCanonicalAscii((string) $row->original_url),
                    'force_https' => (bool) $row->force_https,
                ];
            })
            ->filter(function ($row) {
                return $row['original_url'] !== ''
                    && HostNameValidator::isValidHostLabel($row['original_url']);
            })
            ->values();

        return response()->json(['rules' => $rows]);
    }
}
