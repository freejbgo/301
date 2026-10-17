<?php

namespace App\Services;

use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use App\Support\OpsDisplaySanitizer;

class AcmeChallengeCleanup
{
    /**
     * @param  string[]  $domainNames
     */
    public static function purgeForHosts(array $domainNames): void
    {
        $hosts = [];
        $exact = [];
        foreach ($domainNames as $raw) {
            $raw = (string) $raw;
            if ($raw !== '' && ! OpsDisplaySanitizer::containsControlChars($raw)) {
                $exact[$raw] = true;
            }
            $host = self::canonicalPurgeHost($raw);
            if ($host === '') {
                continue;
            }
            $hosts[$host] = true;
        }
        if ($hosts === [] && $exact === []) {
            return;
        }

        $list = array_keys($hosts);
        $exactList = array_keys($exact);

        $challengeIds = RediAcmeChallenge::query()
            ->get(['id', 'domain_name'])
            ->filter(function ($row) use ($list, $exactList) {
                return self::rowMatchesPurgeTargets((string) $row->domain_name, $list, $exactList);
            })
            ->pluck('id')
            ->all();
        if ($challengeIds !== []) {
            RediAcmeChallenge::query()->whereIn('id', $challengeIds)->delete();
        }

        $syncIds = RediAcmeChallengeNodeSync::query()
            ->get(['id', 'domain_name'])
            ->filter(function ($row) use ($list, $exactList) {
                return self::rowMatchesPurgeTargets((string) $row->domain_name, $list, $exactList);
            })
            ->pluck('id')
            ->all();
        if ($syncIds !== []) {
            RediAcmeChallengeNodeSync::query()->whereIn('id', $syncIds)->delete();
        }
    }

    /**
     * @param  string[]  $canonicalHosts
     * @param  string[]  $exactStoredNames
     */
    protected static function rowMatchesPurgeTargets(string $stored, array $canonicalHosts, array $exactStoredNames): bool
    {
        if (in_array($stored, $canonicalHosts, true) || in_array($stored, $exactStoredNames, true)) {
            return true;
        }
        $key = DomainRecordNormalizer::acmeHostKey($stored);

        return $key !== '' && in_array($key, $canonicalHosts, true);
    }

    /** 停用/删域名时清理挑战：兼容带 scheme 的遗留 original_url。 */
    public static function canonicalPurgeHost(string $raw): string
    {
        return DomainRecordNormalizer::acmeHostKey($raw);
    }
}
