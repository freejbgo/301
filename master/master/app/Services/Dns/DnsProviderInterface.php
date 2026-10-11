<?php

namespace App\Services\Dns;

interface DnsProviderInterface
{
    public function testConnection(): void;

    /**
     * @param  string[]  $ipv4
     */
    public function upsertARecords(string $fqdn, array $ipv4): void;

    public function upsertCname(string $name, string $zone, string $targetFqdn): void;
}
