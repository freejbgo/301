<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\CloudflareDnsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class CloudflareDnsProviderUpsertTest extends TestCase
{
    public function test_list_records_follows_cloudflare_pagination(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => [['id' => 'rec-1', 'type' => 'A', 'content' => '1.1.1.1']],
                'result_info' => ['total_pages' => 2],
            ])),
            new Response(200, [], json_encode([
                'success' => true,
                'result' => [['id' => 'rec-2', 'type' => 'A', 'content' => '2.2.2.2']],
                'result_info' => ['total_pages' => 2],
            ])),
        ]));

        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->dns_zone_id = 'zone123';
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($provider, new Client(['handler' => $stack, 'base_uri' => 'https://api.cloudflare.com/client/v4/']));

        $method = $ref->getMethod('listRecords');
        $method->setAccessible(true);
        $rows = $method->invoke($provider, 'zone123', 'A', 'pool.example.com');

        $this->assertCount(2, $rows);
    }

    public function test_upsert_a_creates_new_ip_before_deleting_stale(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => [
                    ['id' => 'rec-old', 'type' => 'A', 'name' => 'pool.example.com', 'content' => '1.2.3.4'],
                ],
            ])),
            new Response(200, [], json_encode(['success' => true, 'result' => ['id' => 'rec-new']])),
            new Response(200, [], json_encode(['success' => true, 'result' => []])),
        ]));
        $stack->push(Middleware::history($history));

        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->dns_zone_id = 'zone123';
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($provider, new Client(['handler' => $stack, 'base_uri' => 'https://api.cloudflare.com/client/v4/']));

        $provider->upsertARecords('pool.example.com', ['1.2.3.4', '5.6.7.8']);

        $methods = array_map(fn ($tx) => $tx['request']->getMethod(), $history);
        $this->assertSame(['GET', 'POST'], $methods);
        $this->assertStringContainsString('5.6.7.8', (string) $history[1]['request']->getBody());
    }

    public function test_upsert_a_creates_new_ip_before_deleting_removed_one(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => [
                    ['id' => 'rec-old', 'type' => 'A', 'name' => 'pool.example.com', 'content' => '9.9.9.9'],
                ],
            ])),
            new Response(200, [], json_encode(['success' => true, 'result' => ['id' => 'rec-new']])),
            new Response(200, [], json_encode(['success' => true, 'result' => []])),
        ]));
        $stack->push(Middleware::history($history));

        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->dns_zone_id = 'zone123';
        $platform->setApiCredentialsArray(['api_key' => 'token']);

        $provider = new CloudflareDnsProvider($platform);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($provider, new Client(['handler' => $stack, 'base_uri' => 'https://api.cloudflare.com/client/v4/']));

        $provider->upsertARecords('pool.example.com', ['1.2.3.4']);

        $methods = array_map(fn ($tx) => $tx['request']->getMethod(), $history);
        $this->assertSame(['GET', 'POST', 'DELETE'], $methods);
        $this->assertStringContainsString('1.2.3.4', (string) $history[1]['request']->getBody());
    }
}
