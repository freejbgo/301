<?php

namespace App\Services\Dns;

use App\Models\RediNode;
use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\EnsuresValidDnsNames;
use App\Services\Dns\Concerns\ParsesZoneNames;
use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use App\Services\Dns\Support\AliyunDnsClient;
use RuntimeException;

class AliyunDnsProvider implements DnsProviderInterface
{
    use EnsuresValidDnsNames;
    use ParsesZoneNames;
    use ReconcilesDnsRecords;

    protected RediPlatformDns $platform;

    protected AliyunDnsClient $client;

    public function __construct(RediPlatformDns $platform, string $endpoint)
    {
        $this->platform = $platform;
        $creds = $platform->getApiCredentialsArray();
        $id = trim($creds['api_key'] ?? '');
        $secret = trim($creds['api_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw new RuntimeException('阿里云 AccessKey ID / Secret 未配置');
        }
        $this->client = new AliyunDnsClient($id, $secret, $endpoint);
    }

    public function testConnection(): void
    {
        $zone = $this->zoneName();
        $this->client->call('DescribeDomains', [
            'PageNumber' => 1,
            'PageSize' => 1,
            'KeyWord' => $zone,
        ]);
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
                $this->client->call('AddDomainRecord', [
                    'DomainName' => $parts['domain'],
                    'RR' => $parts['rr'],
                    'Type' => 'A',
                    'Value' => $ip,
                    'TTL' => 120,
                ]);
            },
            function (string $id) {
                $this->client->call('DeleteDomainRecord', ['RecordId' => $id]);
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
        $rr = $name;
        $rows = $this->listRecordRows($zone, $rr, 'CNAME');

        $this->reconcileCnameTarget(
            $rows,
            $targetFqdn,
            function (string $target) use ($zone, $rr) {
                $this->client->call('AddDomainRecord', [
                    'DomainName' => $zone,
                    'RR' => $rr,
                    'Type' => 'CNAME',
                    'Value' => $target,
                    'TTL' => 120,
                ]);
            },
            function (string $id, string $target) use ($rr) {
                $this->client->call('UpdateDomainRecord', [
                    'RecordId' => $id,
                    'RR' => $rr,
                    'Type' => 'CNAME',
                    'Value' => $target,
                ]);
            },
            function (string $id) {
                $this->client->call('DeleteDomainRecord', ['RecordId' => $id]);
            }
        );
    }

    /**
     * @return array<int, array{id: string, value: string}>
     */
    protected function listRecordRows(string $domain, string $rr, string $type): array
    {
        $rows = [];
        $page = 1;
        do {
            $res = $this->client->call('DescribeDomainRecords', [
                'DomainName' => $domain,
                'RRKeyWord' => $rr === '@' ? '' : $rr,
                'Type' => $type,
                'PageNumber' => $page,
                'PageSize' => 100,
            ]);
            $records = $res['DomainRecords']['Record'] ?? [];
            if ($records === []) {
                break;
            }
            if (isset($records['RecordId'])) {
                $records = [$records];
            }
            foreach ($records as $row) {
                $rowRr = strtolower((string) ($row['RR'] ?? ''));
                $rowType = strtoupper((string) ($row['Type'] ?? ''));
                if ($rowRr !== strtolower($rr) || $rowType !== strtoupper($type)) {
                    continue;
                }
                if (! empty($row['RecordId'])) {
                    $rows[] = [
                        'id' => (string) $row['RecordId'],
                        'value' => (string) ($row['Value'] ?? ''),
                    ];
                }
            }
            $page++;
            if ($page > 100) {
                throw new RuntimeException('阿里云 DNS 记录分页超过上限，请手工清理重复记录后重试');
            }
        } while (count($records) === 100);

        return $rows;
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
