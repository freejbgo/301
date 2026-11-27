<?php

namespace App\Services\Dns;

use App\Support\RediErrorMessage;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\EnsuresValidDnsNames;
use App\Services\Dns\Concerns\ParsesZoneNames;
use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use GuzzleHttp\Client;
use RuntimeException;

class DnspodDnsProvider implements DnsProviderInterface, SupportsPoolAAAA
{
    use EnsuresValidDnsNames;
    use ParsesZoneNames;
    use ReconcilesDnsRecords;

    protected RediPlatformDns $platform;

    protected Client $http;

    protected string $loginToken;

    protected string $recordLine;

    public function __construct(RediPlatformDns $platform, string $apiBase, ?string $recordLine = null)
    {
        $this->platform = $platform;
        $creds = $platform->getApiCredentialsArray();
        $id = trim($creds['api_key'] ?? '');
        $token = trim($creds['api_secret'] ?? '');
        if ($id === '' || $token === '') {
            throw new RuntimeException(RediErrorMessage::get('dns.dnspod_keys_missing'));
        }
        $this->loginToken = $id.','.$token;
        $line = $recordLine ?? (string) config('redi.dnspod_cn_record_line', '');
        if ($line === '') {
            throw new RuntimeException(RediErrorMessage::get('dns.dnspod_record_line_missing'));
        }
        $this->recordLine = $line;
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
        $fqdn = $this->requireDnsFqdn($fqdn);
        $ipv4 = RediNode::filterValidIpv4List($ipv4);
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
                    'ttl' => $this->platform->recordTtl(),
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

    public function upsertAAAARecords(string $fqdn, array $ipv6): void
    {
        $fqdn = $this->requireDnsFqdn($fqdn);
        $parts = $this->splitFqdn($fqdn, $this->zoneName());
        $sub = $parts['rr'] === '@' ? '@' : $parts['rr'];
        $rows = $this->listRecordRows($parts['domain'], $sub, 'AAAA');

        $this->reconcileAAAARecordValues(
            $rows,
            $ipv6,
            function (string $ip) use ($parts, $sub) {
                $this->request('Record.Create', [
                    'domain' => $parts['domain'],
                    'sub_domain' => $sub,
                    'record_type' => 'AAAA',
                    'record_line' => $this->recordLine,
                    'value' => $ip,
                    'ttl' => $this->platform->recordTtl(),
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
        $zone = $this->requireDnsFqdn($zone, 'DNS zone');
        $targetFqdn = $this->requireDnsFqdn($targetFqdn, 'CNAME target');
        $name = strtolower(rtrim($name, '.'));
        if ($name === $zone) {
            $name = '@';
        } elseif (\Illuminate\Support\Str::endsWith($name, '.'.$zone)) {
            $suffix = substr($name, 0, -strlen('.'.$zone));
            $name = $suffix === '' ? '@' : $suffix;
        }
        $name = $this->requireDnsRrLabel($name);
        $sub = $name;
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
                    'ttl' => $this->platform->recordTtl(),
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
                    'ttl' => $this->platform->recordTtl(),
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
                throw new RuntimeException(RediErrorMessage::get('dns.dnspod_page_limit'));
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
            throw new RuntimeException(RediErrorMessage::get('dns.dnspod_response_invalid'));
        }
        if (isset($body['status']['code']) && (string) $body['status']['code'] !== '1') {
            throw new RuntimeException($body['status']['message'] ?? RediErrorMessage::get('dns.dnspod_api_error'));
        }

        return $body;
    }

    protected function zoneName(): string
    {
        $zone = trim($this->platform->edge_zone, '.');
        if ($zone === '') {
            throw new RuntimeException(RediErrorMessage::get('dns.platform_zone_missing'));
        }

        return strtolower($zone);
    }
}
