<?php

namespace App\Services\Dns;

use App\Models\RediPlatformDns;

class DnsProviderFactory
{
    /** @var list<string> */
    public const API_DRIVERS = [
        'cloudflare',
        'aliyun_cn',
        'aliyun_intl',
        'dnspod_cn',
        'dnspod_intl',
        'dns_com',
    ];

    public static function isSupportedApiDriver(string $driver): bool
    {
        return in_array($driver, self::API_DRIVERS, true);
    }

    /**
     * @return list<string>
     */
    public static function supportedApiDrivers(): array
    {
        return self::API_DRIVERS;
    }

    public static function make(?RediPlatformDns $platform = null): DnsProviderInterface
    {
        $platform = $platform ?: RediPlatformDns::instance();

        if ($platform->dns_mode !== 'api') {
            return new ManualDnsProvider();
        }

        $driver = (string) $platform->dns_driver;

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
            case 'manual':
                return new ManualDnsProvider();
            default:
                throw new \InvalidArgumentException('未知 DNS API 驱动: '.$driver);
        }
    }
}
