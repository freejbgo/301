<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;

class AliyunCnDnsProvider extends AliyunDnsProvider
{
    public function __construct(RediPlatformDns $platform)
    {
        parent::__construct($platform, 'https://dns.aliyuncs.com');
    }
}
