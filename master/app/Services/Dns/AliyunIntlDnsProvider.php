<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;

class AliyunIntlDnsProvider extends AliyunDnsProvider
{
    public function __construct(RediPlatformDns $platform)
    {
        parent::__construct($platform, 'https://alidns.ap-southeast-1.aliyuncs.com');
    }
}
