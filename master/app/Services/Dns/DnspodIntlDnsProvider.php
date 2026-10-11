<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;

class DnspodIntlDnsProvider extends DnspodDnsProvider
{
    public function __construct(RediPlatformDns $platform)
    {
        parent::__construct($platform, 'https://api.dnspod.com', 'Default');
    }
}
