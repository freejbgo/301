<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\AliyunCnDnsProvider;
use App\Services\Dns\Support\AliyunDnsClient;
use Tests\TestCase;

class AliyunDnsProviderUpsertTest extends TestCase
{
    public function test_upsert_aaaa_empty_list_does_not_delete_a_records(): void
    {
        $client = $this->recordingClient([
            [
                'DomainRecords' => [
                    'Record' => [
                        ['RecordId' => 'a-1', 'RR' => 'pool', 'Type' => 'A', 'Value' => '1.2.3.4'],
                        ['RecordId' => 'aaaa-1', 'RR' => 'pool', 'Type' => 'AAAA', 'Value' => '2001:db8::1'],
                    ],
                ],
            ],
            [],
        ]);

        $provider = $this->provider($client);
        $provider->upsertAAAARecords('pool.example.com', []);

        $actions = array_column($client->log, 0);
        $this->assertSame(['DescribeDomainRecords', 'DeleteDomainRecord'], $actions);
        $this->assertSame('aaaa-1', $client->log[1][1]['RecordId']);
    }

    public function test_upsert_aaaa_invalid_desired_does_not_delete(): void
    {
        $client = $this->recordingClient([
            [
                'DomainRecords' => [
                    'Record' => [
                        ['RecordId' => 'aaaa-1', 'RR' => 'pool', 'Type' => 'AAAA', 'Value' => '2001:db8::1'],
                    ],
                ],
            ],
        ]);

        $provider = $this->provider($client);

        try {
            $provider->upsertAAAARecords('pool.example.com', ['not-an-ip']);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not-an-ip', $e->getMessage());
        }

        $this->assertSame(['DescribeDomainRecords'], array_column($client->log, 0));
    }

    public function test_upsert_aaaa_creates_with_type_aaaa_and_rr_at_for_zone_apex(): void
    {
        $client = $this->recordingClient([
            ['DomainRecords' => ['Record' => []]],
            [],
        ]);

        $provider = $this->provider($client);
        $provider->upsertAAAARecords('example.com', ['2001:db8::1']);

        $this->assertSame('AddDomainRecord', $client->log[1][0]);
        $params = $client->log[1][1];
        $this->assertSame('AAAA', $params['Type']);
        $this->assertSame('@', $params['RR']);
        $this->assertSame('2001:db8::1', $params['Value']);
        $this->assertSame('example.com', $params['DomainName']);
        $this->assertSame(600, $params['TTL']);
    }

    private function provider(AliyunDnsClient $client): AliyunCnDnsProvider
    {
        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->setApiCredentialsArray(['api_key' => 'key', 'api_secret' => 'secret']);

        $provider = new AliyunCnDnsProvider($platform);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return $provider;
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    private function recordingClient(array $responses): AliyunDnsClient
    {
        return new class('key', 'secret', $responses) extends AliyunDnsClient
        {
            /** @var array<int, array{0: string, 1: array<string, mixed>}> */
            public array $log = [];

            /** @var array<int, array<string, mixed>> */
            private array $responses;

            /** @param array<int, array<string, mixed>> $responses */
            public function __construct(string $id, string $secret, array $responses)
            {
                parent::__construct($id, $secret, 'https://dns.aliyuncs.com');
                $this->responses = $responses;
            }

            public function call(string $action, array $params = []): array
            {
                $this->log[] = [$action, $params];

                return array_shift($this->responses) ?? [];
            }
        };
    }
}
