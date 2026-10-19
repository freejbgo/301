<?php

namespace App\Services\Dns;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\EnsuresValidDnsNames;
use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use RuntimeException;

class CloudflareDnsProvider implements DnsProviderInterface, SupportsPoolAAAA
{
    use EnsuresValidDnsNames;
    use ReconcilesDnsRecords;

    protected RediPlatformDns $platform;

    protected Client $http;

    public function __construct(RediPlatformDns $platform)
    {
        $this->platform = $platform;
        $creds = $platform->getApiCredentialsArray();
        $token = trim($creds['api_key'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Cloudflare API Token 未配置');
        }
        $zoneId = trim($platform->dns_zone_id ?? '');
        if ($zoneId === '') {
            throw new RuntimeException('Cloudflare Zone ID 未配置');
        }

        $this->http = new Client([
            'base_uri' => 'https://api.cloudflare.com/client/v4/',
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    public function testConnection(): void
    {
        $zoneId = $this->zoneId();
        $res = $this->decode($this->http->get("zones/{$zoneId}"));
        if (empty($res['result']['id'])) {
            throw new RuntimeException('Cloudflare Zone 校验失败');
        }
    }

    public function upsertARecords(string $fqdn, array $ipv4): void
    {
        $fqdn = $this->requireDnsFqdn($fqdn);
        $ipv4 = RediNode::filterValidIpv4List($ipv4);
        $zoneId = $this->zoneId();
        $name = $this->recordNameForFqdn($fqdn);
        $existing = $this->listRecords($zoneId, 'A', $fqdn);
        $rows = array_map(fn ($row) => [
            'id' => (string) ($row['id'] ?? ''),
            'value' => (string) ($row['content'] ?? ''),
        ], $existing);

        $this->reconcileARecordValues(
            $rows,
            $ipv4,
            function (string $ip) use ($zoneId, $name) {
                $this->decode($this->http->post("zones/{$zoneId}/dns_records", [
                    'json' => [
                        'type' => 'A',
                        'name' => $name,
                        'content' => $ip,
                        'ttl' => 120,
                        'proxied' => false,
                    ],
                ]));
            },
            function (string $id) use ($zoneId) {
                $this->http->delete("zones/{$zoneId}/dns_records/{$id}");
            }
        );
    }

    public function upsertAAAARecords(string $fqdn, array $ipv6): void
    {
        $fqdn = $this->requireDnsFqdn($fqdn);
        $zoneId = $this->zoneId();
        $name = $this->recordNameForFqdn($fqdn);
        $existing = $this->listRecords($zoneId, 'AAAA', $fqdn);
        $rows = $this->grayCloudAaaaRows($existing);

        $this->reconcileAAAARecordValues(
            $rows,
            $ipv6,
            function (string $ip) use ($zoneId, $name) {
                $this->decode($this->http->post("zones/{$zoneId}/dns_records", [
                    'json' => [
                        'type' => 'AAAA',
                        'name' => $name,
                        'content' => $ip,
                        'ttl' => 120,
                        'proxied' => false,
                    ],
                ]));
            },
            function (string $id) use ($zoneId) {
                $this->http->delete("zones/{$zoneId}/dns_records/{$id}");
            }
        );
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        $zone = $this->requireDnsFqdn($zone, 'DNS zone');
        $targetFqdn = $this->requireDnsFqdn($targetFqdn, 'CNAME 目标');
        $name = strtolower(rtrim($name, '.'));
        if ($name === $zone) {
            $name = '@';
        } elseif (Str::endsWith($name, '.'.$zone)) {
            $suffix = substr($name, 0, -strlen('.'.$zone));
            $name = $suffix === '' ? '@' : $suffix;
        }
        $name = $this->requireDnsRrLabel($name);

        $zoneId = $this->zoneId();
        $cnameFqdn = $name === '@' ? $zone : $name.'.'.$zone;
        $existing = $this->listRecords($zoneId, 'CNAME', $cnameFqdn);
        $rows = array_map(fn ($row) => [
            'id' => (string) ($row['id'] ?? ''),
            'value' => (string) ($row['content'] ?? ''),
        ], $existing);

        $recordName = $name;
        $this->reconcileCnameTarget(
            $rows,
            $targetFqdn,
            function (string $target) use ($zoneId, $recordName) {
                $this->decode($this->http->post("zones/{$zoneId}/dns_records", [
                    'json' => [
                        'type' => 'CNAME',
                        'name' => $recordName,
                        'content' => $target,
                        'ttl' => 120,
                        'proxied' => false,
                    ],
                ]));
            },
            function (string $id, string $target) use ($zoneId, $recordName) {
                $this->decode($this->http->patch("zones/{$zoneId}/dns_records/{$id}", [
                    'json' => [
                        'type' => 'CNAME',
                        'name' => $recordName,
                        'content' => $target,
                        'ttl' => 120,
                        'proxied' => false,
                    ],
                ]));
            },
            function (string $id) use ($zoneId) {
                $this->http->delete("zones/{$zoneId}/dns_records/{$id}");
            }
        );
    }

    protected function zoneId(): string
    {
        $zoneId = trim($this->platform->dns_zone_id ?? '');
        if ($zoneId === '') {
            throw new RuntimeException('Cloudflare Zone ID 未配置');
        }

        return $zoneId;
    }

    protected function recordNameForFqdn(string $fqdn): string
    {
        $zone = strtolower(rtrim($this->platform->edge_zone, '.'));
        if ($zone !== '' && Str::endsWith($fqdn, '.'.$zone)) {
            $host = substr($fqdn, 0, -strlen('.'.$zone));
            return $host === '' ? '@' : $host;
        }

        return $fqdn;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @return array<int, array{id: string, value: string}>
     */
    protected function grayCloudAaaaRows(array $existing): array
    {
        $rows = [];
        foreach ($existing as $row) {
            if ((string) ($row['type'] ?? '') !== 'AAAA') {
                continue;
            }
            if (! empty($row['proxied'])) {
                continue;
            }
            $rows[] = [
                'id' => (string) ($row['id'] ?? ''),
                'value' => (string) ($row['content'] ?? ''),
            ];
        }

        return $rows;
    }

    protected function listRecords(string $zoneId, string $type, string $name): array
    {
        $all = [];
        for ($page = 1; $page <= 100; $page++) {
            $res = $this->decode($this->http->get("zones/{$zoneId}/dns_records", [
                'query' => [
                    'type' => $type,
                    'name' => $name,
                    'page' => $page,
                    'per_page' => 100,
                ],
            ]));
            $chunk = $res['result'] ?? [];
            if (! is_array($chunk)) {
                $chunk = [];
            }
            $all = array_merge($all, $chunk);
            $totalPages = max(1, (int) ($res['result_info']['total_pages'] ?? 1));
            if ($page >= $totalPages) {
                break;
            }
            if ($page >= 100 && $totalPages > 100) {
                throw new RuntimeException('Cloudflare DNS 记录分页超过上限，请手工清理重复记录后重试');
            }
        }

        return $all;
    }

    protected function decode($response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body) || empty($body['success'])) {
            $errors = $body['errors'] ?? [];
            $msg = $errors[0]['message'] ?? 'Cloudflare API 错误';
            throw new RuntimeException($msg);
        }

        return $body;
    }
}
