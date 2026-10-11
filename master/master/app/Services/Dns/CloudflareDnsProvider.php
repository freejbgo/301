<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use RuntimeException;

class CloudflareDnsProvider implements DnsProviderInterface
{
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
        $fqdn = strtolower(rtrim($fqdn, '.'));
        $ipv4 = array_values(array_unique(array_filter($ipv4)));
        if ($ipv4 === []) {
            throw new RuntimeException('没有可同步的节点 IP');
        }

        $zoneId = $this->zoneId();
        $name = $this->recordNameForFqdn($fqdn);
        $existing = $this->listRecords($zoneId, 'A', $fqdn);

        foreach ($existing as $row) {
            $this->http->delete("zones/{$zoneId}/dns_records/{$row['id']}");
        }

        foreach ($ipv4 as $ip) {
            $this->decode($this->http->post("zones/{$zoneId}/dns_records", [
                'json' => [
                    'type' => 'A',
                    'name' => $name,
                    'content' => $ip,
                    'ttl' => 120,
                    'proxied' => false,
                ],
            ]));
        }
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        $zone = strtolower(rtrim($zone, '.'));
        $targetFqdn = strtolower(rtrim($targetFqdn, '.'));
        $name = strtolower(rtrim($name, '.'));
        if (Str::endsWith($name, '.'.$zone)) {
            $name = substr($name, 0, -strlen('.'.$zone));
        }

        $zoneId = $this->zoneId();
        $cnameFqdn = $name === '@' ? $zone : $name.'.'.$zone;
        $existing = $this->listRecords($zoneId, 'CNAME', $cnameFqdn);
        foreach ($existing as $row) {
            $this->http->delete("zones/{$zoneId}/dns_records/{$row['id']}");
        }

        $this->decode($this->http->post("zones/{$zoneId}/dns_records", [
            'json' => [
                'type' => 'CNAME',
                'name' => $name,
                'content' => $targetFqdn,
                'ttl' => 120,
                'proxied' => false,
            ],
        ]));
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

    protected function listRecords(string $zoneId, string $type, string $name): array
    {
        $res = $this->decode($this->http->get("zones/{$zoneId}/dns_records", [
            'query' => [
                'type' => $type,
                'name' => $name,
            ],
        ]));

        return $res['result'] ?? [];
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
