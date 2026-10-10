<?php

namespace App\Services\Dns;

use RuntimeException;

class ManualDnsProvider implements DnsProviderInterface
{
    public function testConnection(): void
    {
        // 手动模式无需 API
    }

    public function upsertARecords(string $fqdn, array $ipv4): void
    {
        throw new RuntimeException('当前为手动 DNS 模式，请在域名服务商面板修改记录。');
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        throw new RuntimeException('当前为手动 DNS 模式，请在域名服务商面板修改记录。');
    }
}
