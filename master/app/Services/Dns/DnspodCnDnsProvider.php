<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;

class DnspodCnDnsProvider extends DnspodDnsProvider
{
    public function __construct(RediPlatformDns $platform)
    {
        parent::__construct($platform, 'https://dnsapi.cn');
    }
}
