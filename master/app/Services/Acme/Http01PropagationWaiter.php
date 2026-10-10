<?php

namespace App\Services\Acme;

use App\Models\RediNode;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Http01PropagationWaiter
{
    /**
     * 等待全部启用节点与公网域名均可返回 HTTP-01 响应。
     */
    public function wait(string $domain, string $token, string $expectedBody, int $maxSeconds = 120): void
    {
        $domain = strtolower(trim($domain, '.'));
        $path = '/.well-known/acme-challenge/'.$token;
        $publicUrl = 'http://'.$domain.$path;
        $expectedBody = trim($expectedBody);
        $deadline = time() + $maxSeconds;

        while (time() < $deadline) {
            if ($this->allNodesServe($domain, $path, $expectedBody)) {
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
            'HTTP-01 未在 '.$maxSeconds.' 秒内就绪：请确认全部节点 Agent、CNAME 与 80 端口可从公网访问'
        );
    }

    protected function allNodesServe(string $domain, string $path, string $expectedBody): bool
    {
        $nodes = RediNode::enabled()->get();
        if ($nodes->isEmpty()) {
            return false;
        }

        foreach ($nodes as $node) {
            try {
                $response = Http::timeout(6)
                    ->withHeaders(['Host' => $domain])
                    ->get('http://'.$node->public_ip.$path);
                if (! $response->successful() || trim($response->body()) !== $expectedBody) {
                    return false;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }
}
