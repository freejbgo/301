<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\ParsesZoneNames;
use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use GuzzleHttp\Client;
use RuntimeException;

class DnspodDnsProvider implements DnsProviderInterface
{
    use ParsesZoneNames;
    use ReconcilesDnsRecords;

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
        $parts = $this->splitFqdn($fqdn, $this->zoneName());
        $sub = $parts['rr'] === '@' ? '@' : $parts['rr'];
        $rows = $this->listRecordRows($parts['domain'], $sub, 'A');

        $this->reconcileARecordValues(
            $rows,
            $ipv4,
            function (string $ip) use ($parts, $sub) {
                $this->request('Record.Create', [
                    'domain' => $parts['domain'],
                    'sub_domain' => $sub,
                    'record_type' => 'A',
                    'record_line' => $this->recordLine,
                    'value' => $ip,
                    'ttl' => 120,
                ]);
            },
            function (string $id) use ($parts) {
                $this->request('Record.Remove', [
                    'domain' => $parts['domain'],
                    'record_id' => $id,
                ]);
            }
        );
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        $zone = strtolower(rtrim($zone, '.'));
        $name = strtolower(rtrim($name, '.'));
        $targetFqdn = strtolower(rtrim($targetFqdn, '.'));
        $sub = $name === $zone ? '@' : $name;
        $rows = $this->listRecordRows($zone, $sub, 'CNAME');

        $this->reconcileCnameTarget(
            $rows,
            $targetFqdn,
            function (string $target) use ($zone, $sub) {
                $this->request('Record.Create', [
                    'domain' => $zone,
                    'sub_domain' => $sub,
                    'record_type' => 'CNAME',
                    'record_line' => $this->recordLine,
                    'value' => $target,
                    'ttl' => 120,
                ]);
            },
            function (string $id, string $target) use ($zone, $sub) {
                $this->request('Record.Modify', [
                    'domain' => $zone,
                    'record_id' => $id,
                    'sub_domain' => $sub,
                    'record_type' => 'CNAME',
                    'record_line' => $this->recordLine,
                    'value' => $target,
                    'ttl' => 120,
                ]);
            },
            function (string $id) use ($zone) {
                $this->request('Record.Remove', [
                    'domain' => $zone,
                    'record_id' => $id,
                ]);
            }
        );
    }

    /**
     * @return array<int, array{id: string, value: string}>
     */
    protected function listRecordRows(string $domain, string $sub, string $type): array
    {
        $rows = [];
        $offset = 0;
        $page = 0;
        do {
            $page++;
            if ($page > 100) {
                throw new RuntimeException('DNSPod 记录分页超过上限，请手工清理重复记录后重试');
            }
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
                    $rows[] = [
                        'id' => (string) $row['id'],
                        'value' => (string) ($row['value'] ?? ''),
                    ];
                }
            }
            $offset += count($records);
        } while (count($records) === 100);

        return $rows;
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
