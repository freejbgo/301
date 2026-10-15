<?php

namespace App\Services;

use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use App\Support\HostNameValidator;

class AcmeChallengeCleanup
{
    /**
     * @param  string[]  $domainNames
     */
    public static function purgeForHosts(array $domainNames): void
    {
        $hosts = [];
        foreach ($domainNames as $raw) {
            $host = HostNameValidator::toCanonicalAscii((string) $raw);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        if ($hosts === []) {
            return;
        }

        $list = array_keys($hosts);
        RediAcmeChallenge::query()->whereIn('domain_name', $list)->delete();
        RediAcmeChallengeNodeSync::query()->whereIn('domain_name', $list)->delete();
    }
}
