<?php

namespace App\Services;

use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;

class CertificateDeployService
{
    public function queueDeploy(RediDomainCert $cert): void
    {
        $cert->status = RediDomainCert::STATUS_DEPLOYING;
        $cert->save();

        $nodes = RediNode::enabled()->get();
        foreach ($nodes as $node) {
            RediCertDeployment::updateOrCreate(
                [
                    'cert_id' => $cert->id,
                    'node_id' => $node->id,
                ],
                [
                    'status' => 'pending',
                    'last_error' => null,
                    'deployed_at' => null,
                ]
            );
        }

        if ($nodes->isEmpty()) {
            $cert->status = RediDomainCert::STATUS_ACTIVE;
            $cert->save();
        }
    }

    public function markNodeAck(RediDomainCert $cert, RediNode $node, bool $ok, ?string $error = null): void
    {
        RediCertDeployment::where('cert_id', $cert->id)
            ->where('node_id', $node->id)
            ->update([
                'status' => $ok ? 'ok' : 'failed',
                'last_error' => $error,
                'deployed_at' => $ok ? now() : null,
            ]);

        $pending = RediCertDeployment::where('cert_id', $cert->id)
            ->where('status', 'pending')
            ->exists();

        if (! $pending) {
            $failed = RediCertDeployment::where('cert_id', $cert->id)
                ->where('status', 'failed')
                ->exists();
            $cert->status = $failed ? RediDomainCert::STATUS_FAILED : RediDomainCert::STATUS_ACTIVE;
            $cert->save();
        }
    }
}
