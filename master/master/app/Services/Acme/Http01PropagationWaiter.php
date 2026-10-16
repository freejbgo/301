<?php

namespace App\Services\Acme;

use App\Models\RediAcmeChallengeNodeSync;
use App\Models\RediNode;
use App\Support\AcmeHttp01Token;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Http01PropagationWaiter
{
    /**
     * 等待在线启用节点上报已同步挑战，且公网域名可返回 HTTP-01 响应。
     */
    public function wait(string $domain, string $token, string $expectedBody, int $maxSeconds = 120): void
    {
        $maxSeconds = min(600, max(10, $maxSeconds));
        $domain = HostNameValidator::toCanonicalAscii($domain);
        if ($domain === '' || ! HostNameValidator::isValidHostLabel($domain)) {
            throw new RuntimeException('HTTP-01 域名无效');
        }
        try {
            $token = AcmeHttp01Token::normalize($token);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException('HTTP-01 token 无效', 0, $e);
        }
        $path = '/.well-known/acme-challenge/'.$token;
        $publicUrl = 'http://'.$domain.$path;
        $expectedBody = trim($expectedBody);
        if ($expectedBody === '' || OpsDisplaySanitizer::containsControlChars($expectedBody)) {
            throw new RuntimeException('HTTP-01 响应体无效');
        }
        $deadline = time() + max(0, $maxSeconds);
        $pollSeconds = max(1, (int) config('redi.acme_http01_poll_seconds', 3));

        while (time() < $deadline) {
            if ($this->anyOnlineNodeSyncedChallenge($domain)) {
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

            sleep($pollSeconds);
        }

        throw new RuntimeException(
            'HTTP-01 未在 '.$maxSeconds.' 秒内就绪：请确认至少一台在线节点已同步挑战、CNAME 已生效，且公网 80 可访问 '
            .OpsDisplaySanitizer::field($domain, $domain, 253).$path
        );
    }

    /** HTTP-01 只需任一边缘呈现挑战即可，不要求全部在线节点同步。 */
    protected function anyOnlineNodeSyncedChallenge(string $domain): bool
    {
        $ids = RediNode::enabled()
            ->where('last_heartbeat_at', '>=', now()->subMinutes(15))
            ->pluck('id');
        if ($ids->isEmpty()) {
            return false;
        }

        return RediAcmeChallengeNodeSync::query()
            ->where('domain_name', $domain)
            ->whereIn('node_id', $ids)
            ->where('synced_at', '>=', now()->subMinutes(3))
            ->exists();
    }
}
