<?php

namespace App\Services\Acme;

use App\Support\RediErrorMessage;
use App\Models\RediAcmeChallengeNodeSync;
use App\Models\RediNode;
use App\Services\AcmeChallengeQuery;
use App\Support\AcmeHttp01Token;
use App\Services\DomainRecordNormalizer;
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
        $domain = DomainRecordNormalizer::acmeHostKey($domain);
        if ($domain === '') {
            throw new RuntimeException(RediErrorMessage::get('acme.http01_host_invalid'));
        }
        try {
            $token = AcmeHttp01Token::normalize($token);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(RediErrorMessage::get('acme.http01_token_invalid'), 0, $e);
        }
        $path = '/.well-known/acme-challenge/'.$token;
        $publicUrl = 'http://'.$domain.$path;
        if (! AcmeChallengeQuery::http01ContentValid($token, $expectedBody)) {
            throw new RuntimeException(RediErrorMessage::get('acme.http01_body_invalid'));
        }
        $expectedBody = trim($expectedBody);
        $deadline = time() + max(0, $maxSeconds);
        $pollSeconds = max(1, (int) config('redi.acme_http01_poll_seconds', 3));

        while (time() < $deadline) {
            if ($this->anyOnlineNodeSyncedChallenge($domain)) {
                try {
                    $response = Http::timeout(8)
                        ->withHeaders(['User-Agent' => 'redi-master-acme-probe/1.0'])
                        ->get($publicUrl);
                    $probeBody = $response->body();
                    if ($response->successful()
                        && AcmeChallengeQuery::http01ContentValid($token, $probeBody)
                        && trim($probeBody) === $expectedBody) {
                        return;
                    }
                } catch (\Throwable $e) {
                    // retry
                }
            }

            sleep($pollSeconds);
        }

        throw new RuntimeException($this->timeoutMessage($domain, $path, $maxSeconds));
    }

    protected function timeoutMessage(string $domain, string $path, int $maxSeconds): string
    {
        $msg = RediErrorMessage::get('acme.http01_timeout', ['seconds' => $maxSeconds])
            .OpsDisplaySanitizer::field($domain, $domain, 253).$path;
        if (filter_var(config('redi.publish_pool_aaaa', false), FILTER_VALIDATE_BOOLEAN)) {
            $msg .= RediErrorMessage::get('acme.http01_timeout_ipv6');
        }

        return $msg;
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
            ->whereIn('node_id', $ids)
            ->where('synced_at', '>=', now()->subMinutes(3))
            ->get(['domain_name'])
            ->contains(function ($row) use ($domain) {
                $stored = (string) $row->domain_name;

                return $stored === $domain
                    || DomainRecordNormalizer::acmeHostKey($stored) === $domain;
            });
    }
}
