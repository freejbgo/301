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
        $directNames = array_values(array_unique(array_merge($list, $exactList)));

        $challengeIds = $directNames === []
            ? []
            : RediAcmeChallenge::query()->whereIn('domain_name', $directNames)->pluck('id')->all();
        $legacyChallengeIds = RediAcmeChallenge::query()
            ->when($directNames !== [], fn ($query) => $query->whereNotIn('domain_name', $directNames))
            ->get(['id', 'domain_name'])
            ->filter(function ($row) use ($list, $exactList) {
                return self::rowMatchesPurgeTargets((string) $row->domain_name, $list, $exactList);
            })
            ->pluck('id')
            ->all();
        $challengeIds = array_values(array_unique(array_merge($challengeIds, $legacyChallengeIds)));
        if ($challengeIds !== []) {
            RediAcmeChallenge::query()->whereIn('id', $challengeIds)->delete();
        }

        $syncIds = $directNames === []
            ? []
            : RediAcmeChallengeNodeSync::query()->whereIn('domain_name', $directNames)->pluck('id')->all();
        $legacySyncIds = RediAcmeChallengeNodeSync::query()
            ->when($directNames !== [], fn ($query) => $query->whereNotIn('domain_name', $directNames))
            ->get(['id', 'domain_name'])
            ->filter(function ($row) use ($list, $exactList) {
                return self::rowMatchesPurgeTargets((string) $row->domain_name, $list, $exactList);
            })
            ->pluck('id')
            ->all();
        $syncIds = array_values(array_unique(array_merge($syncIds, $legacySyncIds)));
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
