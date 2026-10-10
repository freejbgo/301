<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;

class DnsProviderFactory
{
    public static function make(?RediPlatformDns $platform = null): DnsProviderInterface
    {
        $platform = $platform ?: RediPlatformDns::instance();

        if ($platform->dns_mode !== 'api') {
            return new ManualDnsProvider();
        }

        $driver = $platform->dns_driver;

        switch ($driver) {
            case 'cloudflare':
                return new CloudflareDnsProvider($platform);
            case 'aliyun_cn':
            case 'aliyun_intl':
            case 'dnspod_cn':
            case 'dnspod_intl':
            case 'dns_com':
                return new StubDnsProvider($driver);
            default:
                return new ManualDnsProvider();
        }
    }
}
