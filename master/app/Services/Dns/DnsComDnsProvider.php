<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\ParsesZoneNames;
use GuzzleHttp\Client;
use RuntimeException;

class DnsComDnsProvider implements DnsProviderInterface
{
    use ParsesZoneNames;

    protected RediPlatformDns $platform;

    protected Client $http;

    protected string $apiKey;

    protected string $apiSecret;

    public function __construct(RediPlatformDns $platform)
    {
        $this->platform = $platform;
        $creds = $platform->getApiCredentialsArray();
        $this->apiKey = trim($creds['api_key'] ?? '');
        $this->apiSecret = trim($creds['api_secret'] ?? '');
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('dns.com API Key / Secret 未配置');
        }
        $this->http = new Client([
            'base_uri' => 'https://openapi.dns.com/',
            'timeout' => 30,
        ]);
    }

    public function testConnection(): void
    {
        $this->signedPost('api/domain/list/', ['page' => 1, 'page_size' => 1]);
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
            $this->signedPost('api/record/create/', [
                'domain' => $parts['domain'],
                'record' => $parts['rr'],
                'type' => 'A',
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
        $record = $name === $zone ? '@' : $name;
        $this->deleteRecords($zone, $record, 'CNAME');
        $this->signedPost('api/record/create/', [
            'domain' => $zone,
            'record' => $record,
            'type' => 'CNAME',
            'value' => $targetFqdn,
            'ttl' => 120,
        ]);
    }

    protected function deleteRecords(string $domain, string $record, string $type): void
    {
        $page = 1;
        do {
            $res = $this->signedPost('api/record/list/', [
                'domain' => $domain,
                'record' => $record,
                'type' => $type,
                'page' => $page,
                'page_size' => 100,
            ]);
            $list = $res['data']['list'] ?? $res['data'] ?? [];
            if (! is_array($list)) {
                break;
            }
            foreach ($list as $row) {
                $id = $row['record_id'] ?? $row['id'] ?? null;
                if ($id) {
                    $this->signedPost('api/record/delete/', [
                        'domain' => $domain,
                        'record_id' => $id,
                    ]);
                }
            }
            $page++;
        } while (count($list) === 100);
    }

    protected function signedPost(string $path, array $params): array
    {
        $params['api_key'] = $this->apiKey;
        $params['timestamp'] = (string) time();
        ksort($params);
        $signString = '';
        foreach ($params as $key => $value) {
            $signString .= ($signString === '' ? '' : '&').$key.'='.$value;
        }
        $params['sign'] = md5($signString.$this->apiSecret);

        $response = $this->http->post($path, ['form_params' => $params]);
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new RuntimeException('dns.com 响应无效');
        }
        if (isset($body['code']) && (int) $body['code'] !== 0) {
            throw new RuntimeException($body['message'] ?? 'dns.com API 错误');
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
