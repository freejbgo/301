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

        $challengeIds = RediAcmeChallenge::query()
            ->get(['id', 'domain_name'])
            ->filter(function ($row) use ($list) {
                $stored = (string) $row->domain_name;
                if (in_array($stored, $list, true)) {
                    return true;
                }
                $key = DomainRecordNormalizer::acmeHostKey($stored);

                return $key !== '' && in_array($key, $list, true);
            })
            ->pluck('id')
            ->all();
        if ($challengeIds !== []) {
            RediAcmeChallenge::query()->whereIn('id', $challengeIds)->delete();
        }

        $syncIds = RediAcmeChallengeNodeSync::query()
            ->get(['id', 'domain_name'])
            ->filter(function ($row) use ($list) {
                $stored = (string) $row->domain_name;
                if (in_array($stored, $list, true)) {
                    return true;
                }
                $key = DomainRecordNormalizer::acmeHostKey($stored);

                return $key !== '' && in_array($key, $list, true);
            })
            ->pluck('id')
            ->all();
        if ($syncIds !== []) {
            RediAcmeChallengeNodeSync::query()->whereIn('id', $syncIds)->delete();
        }
    }

    /** 停用/删域名时清理挑战：兼容带 scheme 的遗留 original_url。 */
    public static function canonicalPurgeHost(string $raw): string
    {
        return DomainRecordNormalizer::acmeHostKey($raw);
    }
}
