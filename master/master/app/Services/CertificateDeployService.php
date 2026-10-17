<?php

namespace App\Services;

use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Facades\DB;

class CertificateDeployService
{
    /**
     * 新启用或新注册的节点：为所有应下发的证书补齐 pending 部署行（不重置已 ok 的行）。
     */
    public function syncDeploymentsForNode(RediNode $node): void
    {
        if (! $node->enabled) {
            return;
        }

        foreach ($this->deployableCertsForNodes() as $cert) {
            if (! $cert->getCertificatePem() || ! $cert->getPrivateKeyPem()) {
                continue;
            }

            $existing = RediCertDeployment::query()
                ->where('cert_id', $cert->id)
                ->where('node_id', $node->id)
                ->first();

            if ($existing && $existing->status === 'ok') {
                continue;
            }

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
    }

    /**
     * 将失败部署行重新标为 pending，并推动证书回到 deploying。
     */
    public function requeueFailedDeployments(?int $nodeId = null, ?int $certId = null): int
    {
        $query = RediCertDeployment::query()->where('status', 'failed');
        if ($nodeId !== null) {
            $query->where('node_id', $nodeId);
        }
        if ($certId !== null) {
            $query->where('cert_id', $certId);
        }

        $certIds = (clone $query)->distinct()->pluck('cert_id');

        $count = $query->update([
            'status' => 'pending',
            'last_error' => null,
            'deployed_at' => null,
        ]);

        if ($count === 0) {
            return 0;
        }

        foreach ($certIds as $cid) {
            $cert = RediDomainCert::find($cid);
            if (! $cert) {
                continue;
            }
            $cert->status = RediDomainCert::STATUS_DEPLOYING;
            $cert->last_error = '已重新排队失败节点的证书部署，等待节点拉取';
            $cert->save();
        }

        return $count;
    }

    public function queueDeploy(RediDomainCert $cert): void
    {
        $cert->status = RediDomainCert::STATUS_DEPLOYING;
        $cert->save();

        $nodes = RediNode::enabled()->get();
        $enabledIds = $nodes->pluck('id')->all();
        RediCertDeployment::where('cert_id', $cert->id)
            ->whereNotIn('node_id', $enabledIds)
            ->delete();

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
            $cert->status = RediDomainCert::STATUS_DEPLOYING;
            $cert->last_error = '无启用节点，证书已签发但尚未部署到边缘';
            $cert->save();
        }
    }

    public function markNodeAck(RediDomainCert $cert, RediNode $node, bool $ok, ?string $error = null): bool
    {
        return DB::transaction(function () use ($cert, $node, $ok, $error) {
            $cert = RediDomainCert::query()->whereKey($cert->id)->lockForUpdate()->first();
            if (! $cert) {
                return false;
            }
            $cert->loadMissing('domain');

            $deployment = RediCertDeployment::where('cert_id', $cert->id)
                ->where('node_id', $node->id)
                ->lockForUpdate()
                ->first();

            if (! $deployment) {
                return false;
            }

            $targetStatus = $ok ? 'ok' : 'failed';
            if ($deployment->status === $targetStatus) {
                return true;
            }

            $deployment->status = $targetStatus;
            $deployment->last_error = $error !== null
                ? OpsDisplaySanitizer::logSnippet($error, 2000)
                : null;
            $deployment->deployed_at = $ok ? now() : null;
            $deployment->save();

            $liveNodeIds = RediNode::enabled()
                ->where('last_heartbeat_at', '>=', now()->subMinutes(15))
                ->pluck('id');

            $livePending = RediCertDeployment::where('cert_id', $cert->id)
                ->where('status', 'pending')
                ->whereIn('node_id', $liveNodeIds)
                ->exists();

            $domainActive = $cert->domain && (int) $cert->domain->status === 1;

            if ($livePending) {
                $cert->status = RediDomainCert::STATUS_DEPLOYING;
                if (! $domainActive && $cert->domain) {
                    $cert->last_error = '域名已停用：等待剩余在线节点响应，不会标记为「已生效」';
                }
                $cert->save();

                return true;
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
                if ($domainActive) {
                    $cert->status = RediDomainCert::STATUS_ACTIVE;
                    $cert->last_error = $failedCount > 0 ? '部分节点安装失败，其余节点仍保留该证书' : null;
                } else {
                    $cert->status = RediDomainCert::STATUS_ISSUED;
                    $cert->last_error = '域名已停用：节点已安装证书，但不会标记为「已生效」';
                }
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

            return true;
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, RediDomainCert>
     */
    protected function deployableCertsForNodes()
    {
        return RediDomainCert::query()
            ->with('domain')
            ->whereHas('domain', function ($query) {
                $query->where('status', 1);
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
            ->get()
            ->filter(function (RediDomainCert $cert) {
                if (OpsDisplaySanitizer::containsControlChars((string) $cert->domain_name)) {
                    return false;
                }
                $host = HostNameValidator::toCanonicalAscii((string) $cert->domain_name);

                return $host !== '' && HostNameValidator::isValidHostLabel($host);
            });
    }
}
