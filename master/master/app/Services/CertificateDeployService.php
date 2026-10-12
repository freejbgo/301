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

        $liveNodeIds = RediNode::enabled()
            ->where('last_heartbeat_at', '>=', now()->subMinutes(15))
            ->pluck('id');

        $livePending = RediCertDeployment::where('cert_id', $cert->id)
            ->where('status', 'pending')
            ->whereIn('node_id', $liveNodeIds)
            ->exists();

        if ($livePending) {
            $cert->status = RediDomainCert::STATUS_DEPLOYING;
            $cert->save();

            return;
        }

        $failedCount = RediCertDeployment::where('cert_id', $cert->id)
            ->where('status', 'failed')
            ->count();
        $okCount = RediCertDeployment::where('cert_id', $cert->id)
            ->where('status', 'ok')
            ->count();

        if ($okCount === 0 && $failedCount > 0) {
            $cert->status = RediDomainCert::STATUS_FAILED;
            $cert->last_error = '全部已响应节点安装失败';
        } elseif ($okCount > 0) {
            $cert->status = RediDomainCert::STATUS_ACTIVE;
            $cert->last_error = $failedCount > 0 ? '部分节点安装失败，其余节点仍保留该证书' : null;
        } else {
            $cert->status = RediDomainCert::STATUS_DEPLOYING;
            $pendingAny = RediCertDeployment::where('cert_id', $cert->id)
                ->where('status', 'pending')
                ->exists();
            $cert->last_error = $pendingAny
                ? '等待在线节点安装证书（15 分钟内无心跳的节点不阻塞状态）'
                : '等待节点拉取证书';
        }
        $cert->save();
    }
}
