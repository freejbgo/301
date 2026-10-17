<?php

namespace App\Services;

use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
class AcmeChallengeCleanup
{
    /**
     * @param  string[]  $domainNames
     */
    public static function purgeForHosts(array $domainNames): void
    {
        $hosts = [];
        foreach ($domainNames as $raw) {
            $host = self::canonicalPurgeHost((string) $raw);
            if ($host === '') {
                continue;
            }
            $hosts[$host] = true;
        }
        if ($hosts === []) {
            return;
        }

        $list = array_keys($hosts);
        RediAcmeChallenge::query()->whereIn('domain_name', $list)->delete();
        RediAcmeChallengeNodeSync::query()->whereIn('domain_name', $list)->delete();
    }

    /** 停用/删域名时清理挑战：兼容带 scheme 的遗留 original_url。 */
    public static function canonicalPurgeHost(string $raw): string
    {
        return DomainRecordNormalizer::acmeHostKey($raw);
    }
}
