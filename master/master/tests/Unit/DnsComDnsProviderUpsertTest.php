<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnsComDnsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class DnsComDnsProviderUpsertTest extends TestCase
{
    public function test_upsert_cname_updates_before_deleting_extra(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'code' => 0,
                'data' => [
                    'list' => [
                        ['record_id' => '99', 'record' => 'token', 'type' => 'CNAME', 'value' => 'old.pool.example.com'],
                    ],
                ],
            ])),
            new Response(200, [], json_encode(['code' => 0])),
        ]));
        $stack->push(Middleware::history($history));

        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->setApiCredentialsArray(['api_key' => 'k', 'api_secret' => 's']);

        $provider = new DnsComDnsProvider($platform);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($provider, new Client(['handler' => $stack, 'base_uri' => 'https://openapi.dns.com/']));

        $provider->upsertCname('token', 'example.com', 'pool.example.com');

        $paths = array_map(fn ($tx) => $tx['request']->getUri()->getPath(), $history);
        $this->assertStringContainsString('record/list', $paths[0]);
        $this->assertStringContainsString('record/update', $paths[1]);
    }
}
