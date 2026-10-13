<?php

namespace App\Services\Acme;

use App\Models\RediAcmeChallengeNodeSync;
use App\Models\RediNode;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Http01PropagationWaiter
{
    /**
     * 等待在线启用节点上报已同步挑战，且公网域名可返回 HTTP-01 响应。
     */
    public function wait(string $domain, string $token, string $expectedBody, int $maxSeconds = 120): void
    {
        $domain = strtolower(trim($domain, '.'));
        $path = '/.well-known/acme-challenge/'.$token;
        $publicUrl = 'http://'.$domain.$path;
        $expectedBody = trim($expectedBody);
        $deadline = time() + $maxSeconds;

        while (time() < $deadline) {
            if ($this->allNodesSynced($domain)) {
                try {
                    $response = Http::timeout(8)
                        ->withHeaders(['User-Agent' => 'redi-master-acme-probe/1.0'])
                        ->get($publicUrl);
                    if ($response->successful() && trim($response->body()) === $expectedBody) {
                        return;
                    }
                } catch (\Throwable $e) {
                    // retry
                }
            }

            sleep(3);
        }

        throw new RuntimeException(
            'HTTP-01 未在 '.$maxSeconds.' 秒内就绪：请确认 15 分钟内有心跳的在线节点已同步挑战、CNAME 已生效，且公网 80 可访问 '.$publicUrl
        );
    }

    protected function allNodesSynced(string $domain): bool
    {
        $ids = RediNode::enabled()
            ->where('last_heartbeat_at', '>=', now()->subMinutes(15))
            ->pluck('id');
        if ($ids->isEmpty()) {
            return false;
        }

        $synced = RediAcmeChallengeNodeSync::query()
            ->where('domain_name', $domain)
            ->whereIn('node_id', $ids)
            ->where('synced_at', '>=', now()->subMinutes(3))
            ->pluck('node_id')
            ->unique();

        return $synced->count() >= $ids->count();
    }
}
