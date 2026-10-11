<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\ParsesZoneNames;
use GuzzleHttp\Client;
use RuntimeException;

class DnspodDnsProvider implements DnsProviderInterface
{
    use ParsesZoneNames;

    protected RediPlatformDns $platform;

    protected Client $http;

    protected string $loginToken;

    protected string $recordLine;

    public function __construct(RediPlatformDns $platform, string $apiBase, string $recordLine = '默认')
    {
        $this->platform = $platform;
        $creds = $platform->getApiCredentialsArray();
        $id = trim($creds['api_key'] ?? '');
        $token = trim($creds['api_secret'] ?? '');
        if ($id === '' || $token === '') {
            throw new RuntimeException('DNSPod API ID / Token 未配置（填在 API Key / Secret）');
        }
        $this->loginToken = $id.','.$token;
        $this->recordLine = $recordLine;
        $this->http = new Client([
            'base_uri' => rtrim($apiBase, '/').'/',
            'timeout' => 30,
        ]);
    }

    public function testConnection(): void
    {
        $this->request('Domain.List', ['length' => 1]);
    }

    public function upsertARecords(string $fqdn, array $ipv4): void
    {
        $ipv4 = array_values(array_unique(array_filter($ipv4)));
        if ($ipv4 === []) {
            throw new RuntimeException('没有可同步的节点 IP');
        }
        $parts = $this->splitFqdn($fqdn, $this->zoneName());
        $this->deleteRecords($parts['domain'], $parts['rr'], 'A');
        foreach ($ipv4 as $ip) {
            $this->request('Record.Create', [
                'domain' => $parts['domain'],
                'sub_domain' => $parts['rr'] === '@' ? '@' : $parts['rr'],
                'record_type' => 'A',
                'record_line' => $this->recordLine,
                'value' => $ip,
                'ttl' => 120,
            ]);
        }
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        $zone = strtolower(rtrim($zone, '.'));
        $name = strtolower(rtrim($name, '.'));
        $targetFqdn = strtolower(rtrim($targetFqdn, '.'));
        $sub = $name === $zone ? '@' : $name;
        $this->deleteRecords($zone, $sub, 'CNAME');
        $this->request('Record.Create', [
            'domain' => $zone,
            'sub_domain' => $sub,
            'record_type' => 'CNAME',
            'record_line' => $this->recordLine,
            'value' => $targetFqdn,
            'ttl' => 120,
        ]);
    }

    protected function deleteRecords(string $domain, string $sub, string $type): void
    {
        $ids = [];
        $offset = 0;
        do {
            $res = $this->request('Record.List', [
                'domain' => $domain,
                'sub_domain' => $sub === '@' ? '' : $sub,
                'record_type' => $type,
                'offset' => $offset,
                'length' => 100,
            ]);
            $records = $res['records'] ?? [];
            foreach ($records as $row) {
                $name = strtolower((string) ($row['name'] ?? ''));
                $rowType = strtoupper((string) ($row['type'] ?? ''));
                if ($name !== strtolower($sub) || $rowType !== strtoupper($type)) {
                    continue;
                }
                if (! empty($row['id'])) {
                    $ids[] = $row['id'];
                }
            }
            $offset += count($records);
        } while (count($records) === 100);

        foreach ($ids as $id) {
            $this->request('Record.Remove', [
                'domain' => $domain,
                'record_id' => $id,
            ]);
        }
    }

    protected function request(string $action, array $params = []): array
    {
        $params = array_merge([
            'login_token' => $this->loginToken,
            'format' => 'json',
            'lang' => 'cn',
            'error_on_empty' => 'no',
        ], $params);

        $response = $this->http->post('', ['query' => ['action' => $action], 'form_params' => $params]);
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new RuntimeException('DNSPod 响应无效');
        }
        if (isset($body['status']['code']) && (string) $body['status']['code'] !== '1') {
            throw new RuntimeException($body['status']['message'] ?? 'DNSPod API 错误');
        }

        return $body;
    }

    protected function zoneName(): string
    {
        $zone = trim($this->platform->edge_zone, '.');
        if ($zone === '') {
            throw new RuntimeException('平台主域名未配置');
        }

        return strtolower($zone);
    }
}
