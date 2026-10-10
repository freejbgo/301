<?php

namespace App\Services\Dns;

use RuntimeException;

class StubDnsProvider implements DnsProviderInterface
{
    protected string $driver;

    public function __construct(string $driver)
    {
        $this->driver = $driver;
    }

    public function testConnection(): void
    {
        throw new RuntimeException("DNS 驱动 {$this->driver} 尚未实现，请先用 Cloudflare 或手动模式。");
    }

    public function upsertARecords(string $fqdn, array $ipv4): void
    {
        $this->testConnection();
    }

    public function upsertCname(string $name, string $zone, string $targetFqdn): void
    {
        $this->testConnection();
    }
}
