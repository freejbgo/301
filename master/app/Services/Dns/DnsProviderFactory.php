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
                return new AliyunCnDnsProvider($platform);
            case 'aliyun_intl':
                return new AliyunIntlDnsProvider($platform);
            case 'dnspod_cn':
                return new DnspodCnDnsProvider($platform);
            case 'dnspod_intl':
                return new DnspodIntlDnsProvider($platform);
            case 'dns_com':
                return new DnsComDnsProvider($platform);
            default:
                return new ManualDnsProvider();
        }
    }
}
