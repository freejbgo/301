<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnsComDnsProvider;
use Tests\TestCase;

class DnsComDnsProviderUpsertTest extends TestCase
{
    public function test_upsert_aaaa_empty_list_skips_empty_type_ipv4_and_deletes_aaaa(): void
    {
        $provider = $this->provider([
            [
                'data' => [
                    'list' => [
                        ['record_id' => 'a-like', 'record' => 'pool', 'type' => '', 'value' => '1.2.3.4'],
                        ['record_id' => 'aaaa-1', 'record' => 'pool', 'type' => 'AAAA', 'value' => '2001:db8::1'],
                    ],
                ],
            ],
            [],
        ]);

        $provider->upsertAAAARecords('pool.example.com', []);

        $paths = array_column($provider->log, 0);
        $this->assertSame(['api/record/list/', 'api/record/delete/'], $paths);
        $this->assertSame('aaaa-1', $provider->log[1][1]['record_id']);
    }

    public function test_upsert_aaaa_invalid_desired_does_not_delete(): void
    {
        $provider = $this->provider([
            [
                'data' => [
                    'list' => [
                        ['record_id' => 'aaaa-1', 'record' => 'pool', 'type' => 'AAAA', 'value' => '2001:db8::1'],
                    ],
                ],
            ],
        ]);

        try {
            $provider->upsertAAAARecords('pool.example.com', ['not-an-ip']);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not-an-ip', $e->getMessage());
        }

        $this->assertSame(['api/record/list/'], array_column($provider->log, 0));
    }

    public function test_upsert_aaaa_create_uses_type_aaaa(): void
    {
        $provider = $this->provider([
            ['data' => ['list' => []]],
            [],
        ]);

        $provider->upsertAAAARecords('pool.example.com', ['2001:db8::1']);

        $this->assertSame('api/record/create/', $provider->log[1][0]);
        $params = $provider->log[1][1];
        $this->assertSame('AAAA', $params['type']);
        $this->assertSame('2001:db8::1', $params['value']);
        $this->assertSame('pool', $params['record']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    private function provider(array $responses): DnsComDnsProvider
    {
        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->setApiCredentialsArray(['api_key' => 'key', 'api_secret' => 'secret']);

        return new class($platform, $responses) extends DnsComDnsProvider
        {
            /** @var array<int, array{0: string, 1: array<string, mixed>}> */
            public array $log = [];

            /** @var array<int, array<string, mixed>> */
            private array $responses;

            /** @param array<int, array<string, mixed>> $responses */
            public function __construct(RediPlatformDns $platform, array $responses)
            {
                parent::__construct($platform);
                $this->responses = $responses;
            }

            protected function signedPost(string $path, array $params): array
            {
                $this->log[] = [$path, $params];

                return array_shift($this->responses) ?? [];
            }
        };
    }
}
