<?php

namespace App\Services;

use App\Models\RediAdminDomain;
use App\Models\RediCertDeployment;
use App\Models\RediDomainCert;
use App\Models\RediNode;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CertificateDeployService
{
    /**
     * 与节点 API `/certificates` 一致：该证是否会被下发给边缘（含 PEM、域名一致、启用域名）。
     */
    public static function certIsNodePullable(RediDomainCert $cert): bool
    {
        $cert->loadMissing('domain');
        if (! $cert->domain || (int) $cert->domain->status !== 1) {
            return false;
        }

        DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert);
        if (! DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
            return false;
        }

        if (! self::certStatusAllowsNodePull($cert)) {
            return false;
        }

        return $cert->getCertificatePem() && $cert->getPrivateKeyPem();
    }

    /** @var array{pending: int, failed: int}|null */
    protected static $actionableDeploymentStatusCounts = null;

    /**
     * `redi:health` / JSON summary：仅统计边缘仍能拉取证书的部署行。
     */
    public static function countActionableDeployments(string $status): int
    {
        if (! in_array($status, ['pending', 'failed'], true)) {
            return 0;
        }

        self::hydrateActionableDeploymentStatusCounts();

        return (int) (self::$actionableDeploymentStatusCounts[$status] ?? 0);
    }

    /**
     * @return Collection<int, object{node_id: int, pending_count: int, failed_count: int}>
     */
    public static function deploymentCountsByNode(): Collection
    {
        $pendingByNode = [];
        $failedByNode = [];

        RediCertDeployment::query()
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('node', function ($query) {
                $query->where('enabled', true);
            })
            ->with(['cert.domain'])
            ->get(['id', 'node_id', 'status', 'cert_id'])
            ->each(function (RediCertDeployment $deployment) use (&$pendingByNode, &$failedByNode) {
                $cert = $deployment->cert;
                if (! $cert || ! self::certIsNodePullable($cert)) {
                    return;
                }

                $nodeId = (int) $deployment->node_id;
                if ($deployment->status === 'failed') {
                    $failedByNode[$nodeId] = ($failedByNode[$nodeId] ?? 0) + 1;

                    return;
                }

                $pendingByNode[$nodeId] = ($pendingByNode[$nodeId] ?? 0) + 1;
            });

        $nodeIds = array_unique(array_merge(array_keys($pendingByNode), array_keys($failedByNode)));

        return collect($nodeIds)->map(function (int $nodeId) use ($pendingByNode, $failedByNode) {
            return (object) [
                'node_id' => $nodeId,
                'pending_count' => $pendingByNode[$nodeId] ?? 0,
                'failed_count' => $failedByNode[$nodeId] ?? 0,
            ];
        });
    }

    /**
     * 与 `queueDeploy` 入队前校验一致（不检查证书 status，便于 failed 行重试）。
     */
    public static function certIsEligibleForDeployQueue(RediDomainCert $cert): bool
    {
        $cert->loadMissing('domain');
        if (! DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert)
            || ! DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
            return false;
        }

        if (! $cert->getCertificatePem() || ! $cert->getPrivateKeyPem()) {
            return false;
        }

        return $cert->domain && (int) $cert->domain->status === 1;
    }

    public static function forgetActionableDeploymentMetrics(): void
    {
        self::$actionableDeploymentStatusCounts = null;
    }

    protected static function hydrateActionableDeploymentStatusCounts(): void
    {
        if (! app()->runningUnitTests() && self::$actionableDeploymentStatusCounts !== null) {
            return;
        }

        $counts = ['pending' => 0, 'failed' => 0];
        RediCertDeployment::query()
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('node', function ($query) {
                $query->where('enabled', true);
            })
            ->with(['cert.domain'])
            ->get(['id', 'status', 'cert_id'])
            ->each(function (RediCertDeployment $deployment) use (&$counts) {
                $cert = $deployment->cert;
                if (! $cert || ! self::certIsNodePullable($cert)) {
                    return;
                }

                if (isset($counts[$deployment->status])) {
                    $counts[$deployment->status]++;
                }
            });

        self::$actionableDeploymentStatusCounts = $counts;
    }

    protected static function certStatusAllowsNodePull(RediDomainCert $cert): bool
    {
        if (in_array($cert->status, [
            RediDomainCert::STATUS_ISSUED,
            RediDomainCert::STATUS_DEPLOYING,
            RediDomainCert::STATUS_ACTIVE,
        ], true)) {
            return true;
        }

        if ($cert->status === RediDomainCert::STATUS_VALIDATING) {
            return $cert->certificate !== null && $cert->certificate !== '';
        }

        return false;
    }

    /**
     * 新启用或新注册的节点：为所有应下发的证书补齐 pending 部署行（不重置已 ok 的行）。
     */
    public function handleDomainReenabledFromDisabled(RediAdminDomain $domain): void
    {
        $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
        if (! $cert) {
            return;
        }

        $cert->setRelation('domain', $domain);
        if ($cert->last_error === RediAdminDomain::DISABLE_DEPLOY_CANCEL_MSG) {
            $cert->last_error = null;
            $cert->save();
        }

        if (self::certIsEligibleForDeployQueue($cert)) {
            $this->queueDeploy($cert);
        }
    }

    /**
     * 证书已不可被节点拉取时，删除 pending/failed 部署行（保留 ok，便于审计）。
     */
    public function pruneNonPullableDeployments(RediDomainCert $cert): int
    {
        $cert->loadMissing('domain');
        if (self::certIsNodePullable($cert)) {
            return 0;
        }

        $deleted = (int) RediCertDeployment::query()
            ->where('cert_id', $cert->id)
            ->whereIn('status', ['pending', 'failed'])
            ->delete();

        if ($deleted > 0) {
            self::forgetActionableDeploymentMetrics();
        }

        return $deleted;
    }

    public function syncDeploymentsForNode(RediNode $node): void
    {
        if (! $node->enabled) {
            return;
        }

        foreach ($this->deployableCertsForNodes() as $cert) {
            if (! self::certIsNodePullable($cert)) {
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

        $this->pruneStaleDeploymentsForNode($node);

        self::forgetActionableDeploymentMetrics();
    }

    public function pruneStaleDeploymentsForNode(RediNode $node): void
    {
        $pruned = false;
        RediCertDeployment::query()
            ->where('node_id', $node->id)
            ->whereIn('status', ['pending', 'failed'])
            ->with(['cert.domain'])
            ->get()
            ->each(function (RediCertDeployment $deployment) use (&$pruned) {
                $cert = $deployment->cert;
                if (! $cert || ! self::certIsNodePullable($cert)) {
                    $deployment->delete();
                    $pruned = true;
                }
            });

        if ($pruned) {
            self::forgetActionableDeploymentMetrics();
        }
    }

    /**
     * 将失败部署行重新标为 pending，并推动证书回到 deploying。
     */
    public function requeueFailedDeployments(?int $nodeId = null, ?int $certId = null): int
    {
        $query = RediCertDeployment::query()
            ->where('status', 'failed')
            ->whereHas('node', function ($nodeQuery) {
                $nodeQuery->where('enabled', true);
            });
        if ($nodeId !== null) {
            $query->where('node_id', $nodeId);
        }
        if ($certId !== null) {
            $query->where('cert_id', $certId);
        }

        $certIds = (clone $query)->distinct()->pluck('cert_id');
        $eligibleCertIds = [];
        foreach ($certIds as $cid) {
            $cert = RediDomainCert::query()->with('domain')->find($cid);
            if (! $cert || ! self::certIsEligibleForDeployQueue($cert)) {
                continue;
            }
            $eligibleCertIds[] = (int) $cid;
        }

        if ($eligibleCertIds === []) {
            return 0;
        }

        $count = (clone $query)
            ->whereIn('cert_id', $eligibleCertIds)
            ->update([
                'status' => 'pending',
                'last_error' => null,
                'deployed_at' => null,
            ]);

        if ($count === 0) {
            return 0;
        }

        foreach ($eligibleCertIds as $cid) {
            $cert = RediDomainCert::query()->with('domain')->find($cid);
            if (! $cert) {
                continue;
            }
            $cert->status = RediDomainCert::STATUS_DEPLOYING;
            $cert->last_error = '已重新排队失败节点的证书部署，等待节点拉取';
            $cert->save();
        }

        self::forgetActionableDeploymentMetrics();

        return $count;
    }

    public function queueDeploy(RediDomainCert $cert): bool
    {
        $cert->loadMissing('domain');
        if (! DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert)
            || ! DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
            return $this->refuseQueueDeploy($cert, '域名主机名无效或与证书不一致，已跳过部署排队');
        }

        if (! $cert->getCertificatePem() || ! $cert->getPrivateKeyPem()) {
            return $this->refuseQueueDeploy($cert, '证书材料不可用，已跳过部署排队');
        }

        if (! $cert->domain || (int) $cert->domain->status !== 1) {
            return $this->refuseQueueDeploy($cert, '域名已停用，已跳过部署排队');
        }

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

        self::forgetActionableDeploymentMetrics();

        return true;
    }

    public function markNodeAck(RediDomainCert $cert, RediNode $node, bool $ok, ?string $error = null): bool
    {
        $applied = DB::transaction(function () use ($cert, $node, $ok, $error) {
            $node = RediNode::query()->whereKey($node->id)->lockForUpdate()->first();
            if (! $node || ! $node->enabled) {
                return false;
            }

            $cert = RediDomainCert::query()->whereKey($cert->id)->lockForUpdate()->first();
            if (! $cert) {
                return false;
            }
            $cert->loadMissing('domain');
            DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert);
            if ($ok && ! DomainRecordNormalizer::certMatchesAdminDomain($cert)) {
                $ok = false;
                if ($error === null || $error === '') {
                    $error = '证书域名与后台域名不一致，已拒绝确认';
                }
            }
            if ($ok && (! $cert->getCertificatePem() || ! $cert->getPrivateKeyPem())) {
                $ok = false;
                if ($error === null || $error === '') {
                    $error = '主控证书材料不可用，已拒绝确认';
                }
            }
            if ($ok && (! $cert->domain || (int) $cert->domain->status !== 1)) {
                $ok = false;
                if ($error === null || $error === '') {
                    $error = '域名已停用或已删除，已拒绝确认';
                }
            }

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

        self::forgetActionableDeploymentMetrics();

        return $applied;
    }

    protected function refuseQueueDeploy(RediDomainCert $cert, string $message): bool
    {
        $this->pruneNonPullableDeployments($cert);
        $cert->last_error = $message;
        $cert->save();

        return false;
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
            ->each(function (RediDomainCert $cert) {
                DomainRecordNormalizer::realignCertDomainNameFromAdminDomain($cert);
            })
            ->filter(function (RediDomainCert $cert) {
                return DomainRecordNormalizer::certMatchesAdminDomain($cert);
            });
    }
}
