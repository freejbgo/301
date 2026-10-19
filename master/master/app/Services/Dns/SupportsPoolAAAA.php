<?php

namespace App\Services\Dns;

interface SupportsPoolAAAA
{
    /**
     * @param  string[]  $ipv6
     */
    public function upsertAAAARecords(string $fqdn, array $ipv6): void;
}
