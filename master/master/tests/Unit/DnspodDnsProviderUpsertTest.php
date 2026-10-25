<?php

namespace Tests\Unit;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnspodCnDnsProvider;
use App\Services\Dns\DnspodDnsProvider;
use Tests\TestCase;

class DnspodDnsProviderUpsertTest extends TestCase
{
    public function test_upsert_aaaa_empty_list_does_not_delete_a_records(): void
    {
        $provider = $this->provider([
            [
                'records' => [
                    ['id' => 'a-1', 'name' => 'pool', 'type' => 'A', 'value' => '1.2.3.4'],
                    ['id' => 'aaaa-1', 'name' => 'pool', 'type' => 'AAAA', 'value' => '2001:db8::1'],
                ],
            ],
            [],
        ]);

        $provider->upsertAAAARecords('pool.example.com', []);

        $actions = array_column($provider->log, 0);
        $this->assertSame(['Record.List', 'Record.Remove'], $actions);
        $this->assertSame('aaaa-1', $provider->log[1][1]['record_id']);
    }

    public function test_upsert_aaaa_invalid_desired_does_not_delete(): void
    {
        $provider = $this->provider([
            [
                'records' => [
                    ['id' => 'aaaa-1', 'name' => 'pool', 'type' => 'AAAA', 'value' => '2001:db8::1'],
                ],
            ],
        ]);

        try {
            $provider->upsertAAAARecords('pool.example.com', ['not-an-ip']);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not-an-ip', $e->getMessage());
        }

        $this->assertSame(['Record.List'], array_column($provider->log, 0));
    }

    public function test_upsert_aaaa_create_uses_record_line_and_sub_domain_at_for_apex(): void
    {
        $provider = $this->provider([
            ['records' => []],
            [],
        ]);

        $provider->upsertAAAARecords('example.com', ['2001:db8::1']);

        $this->assertSame('Record.Create', $provider->log[1][0]);
        $params = $provider->log[1][1];
        $this->assertSame('AAAA', $params['record_type']);
        $this->assertSame('@', $params['sub_domain']);
        $this->assertSame('默认', $params['record_line']);
        $this->assertSame('2001:db8::1', $params['value']);
    }

    public function test_rejects_empty_configured_record_line(): void
    {
        config(['redi.dnspod_cn_record_line' => '']);

        $platform = new RediPlatformDns();
        $platform->setApiCredentialsArray(['api_key' => 'id', 'api_secret' => 'token']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNSPod China record line is not configured');

        new DnspodCnDnsProvider($platform);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    private function provider(array $responses): DnspodDnsProvider
    {
        $platform = new RediPlatformDns();
        $platform->edge_zone = 'example.com';
        $platform->setApiCredentialsArray(['api_key' => 'id', 'api_secret' => 'token']);

        return new class($platform, $responses) extends DnspodCnDnsProvider
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

            protected function request(string $action, array $params = []): array
            {
                $this->log[] = [$action, $params];

                return array_shift($this->responses) ?? [];
            }
        };
    }
}
