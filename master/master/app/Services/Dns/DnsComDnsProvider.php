<?php

namespace App\Services\Dns;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\EnsuresValidDnsNames;
use App\Services\Dns\Concerns\ParsesZoneNames;
use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use GuzzleHttp\Client;
use RuntimeException;

class DnsComDnsProvider implements DnsProviderInterface
{
    use EnsuresValidDnsNames;
    use ParsesZoneNames;
    use ReconcilesDnsRecords;

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
        $fqdn = $this->requireDnsFqdn($fqdn);
        $ipv4 = RediNode::filterValidIpv4List($ipv4);
        $parts = $this->splitFqdn($fqdn, $this->zoneName());
        $rows = $this->listRecordRows($parts['domain'], $parts['rr'], 'A');

        $this->reconcileARecordValues(
            $rows,
            $ipv4,
            function (string $ip) use ($parts) {
                $this->signedPost('api/record/create/', [
                    'domain' => $parts['domain'],
                    'record' => $parts['rr'],
                    'type' => 'A',
                    'value' => $ip,
                    'ttl' => 120,
                ]);
            },
            function (string $id) use ($parts) {
                $this->signedPost('api/record/delete/', [
                    'domain' => $parts['domain'],
                    'record_id' => $id,
                ]);
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
        } elseif (\Illuminate\Support\Str::endsWith($name, '.'.$zone)) {
            $suffix = substr($name, 0, -strlen('.'.$zone));
            $name = $suffix === '' ? '@' : $suffix;
        }
        $name = $this->requireDnsRrLabel($name);
        $record = $name;
        $rows = $this->listRecordRows($zone, $record, 'CNAME');

        $this->reconcileCnameTarget(
            $rows,
            $targetFqdn,
            function (string $target) use ($zone, $record) {
                $this->signedPost('api/record/create/', [
                    'domain' => $zone,
                    'record' => $record,
                    'type' => 'CNAME',
                    'value' => $target,
                    'ttl' => 120,
                ]);
            },
            function (string $id, string $target) use ($zone, $record) {
                $this->signedPost('api/record/update/', [
                    'domain' => $zone,
                    'record_id' => $id,
                    'record' => $record,
                    'type' => 'CNAME',
                    'value' => $target,
                    'ttl' => 120,
                ]);
            },
            function (string $id) use ($zone) {
                $this->signedPost('api/record/delete/', [
                    'domain' => $zone,
                    'record_id' => $id,
                ]);
            }
        );
    }

    /**
     * @return array<int, array{id: string, value: string}>
     */
    protected function listRecordRows(string $domain, string $record, string $type): array
    {
        $rows = [];
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
                if (! is_array($row)) {
                    continue;
                }
                $rowName = strtolower((string) ($row['record'] ?? $row['host'] ?? ''));
                $rowType = strtoupper((string) ($row['type'] ?? ''));
                if ($rowName !== strtolower($record) || ($rowType !== '' && $rowType !== strtoupper($type))) {
                    continue;
                }
                $id = $row['record_id'] ?? $row['id'] ?? null;
                if ($id) {
                    $rows[] = [
                        'id' => (string) $id,
                        'value' => (string) ($row['value'] ?? ''),
                    ];
                }
            }
            $page++;
            if ($page > 100) {
                throw new RuntimeException('dns.com 记录分页超过上限，请手工清理重复记录后重试');
            }
        } while (count($list) === 100);

        return $rows;
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
