<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;
use App\Services\Dns\Concerns\ParsesZoneNames;
use App\Services\Dns\Support\AliyunDnsClient;
use RuntimeException;

class AliyunDnsProvider implements DnsProviderInterface
{
    use ParsesZoneNames;

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
        $ipv4 = array_values(array_unique(array_filter($ipv4)));
        if ($ipv4 === []) {
            throw new RuntimeException('没有可同步的节点 IP');
        }
        $parts = $this->splitFqdn($fqdn, $this->zoneName());
        $this->deleteRecords($parts['domain'], $parts['rr'], 'A');
        foreach ($ipv4 as $ip) {
            $this->client->call('AddDomainRecord', [
                'DomainName' => $parts['domain'],
                'RR' => $parts['rr'],
                'Type' => 'A',
                'Value' => $ip,
                'TTL' => 120,
            ]);
        }
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        $zone = strtolower(rtrim($zone, '.'));
        $name = strtolower(rtrim($name, '.'));
        $targetFqdn = strtolower(rtrim($targetFqdn, '.'));
        $this->deleteRecords($zone, $name === $zone ? '@' : $name, 'CNAME');
        $this->client->call('AddDomainRecord', [
            'DomainName' => $zone,
            'RR' => $name === $zone ? '@' : $name,
            'Type' => 'CNAME',
            'Value' => $targetFqdn,
            'TTL' => 120,
        ]);
    }

    protected function deleteRecords(string $domain, string $rr, string $type): void
    {
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
                return;
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
                    $this->client->call('DeleteDomainRecord', ['RecordId' => $row['RecordId']]);
                }
            }
            $page++;
        } while (count($records) === 100 && $page <= 20);
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
