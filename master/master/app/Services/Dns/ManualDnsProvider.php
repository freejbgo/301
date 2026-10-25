<?php

namespace App\Services\Dns;

use App\Support\RediErrorMessage;

use RuntimeException;

class ManualDnsProvider implements DnsProviderInterface
{
    public function testConnection(): void
    {
        // 手动模式无需 API
    }

    public function upsertARecords(string $fqdn, array $ipv4): void
    {
        throw new RuntimeException(RediErrorMessage::get('dns.manual_mode'));
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        throw new RuntimeException(RediErrorMessage::get('dns.manual_mode'));
    }
}
