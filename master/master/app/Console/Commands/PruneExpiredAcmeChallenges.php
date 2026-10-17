<?php

namespace App\Console\Commands;

use App\Models\RediAcmeChallenge;
use App\Services\AcmeChallengeCleanup;
use App\Services\AcmeChallengeQuery;
use Illuminate\Console\Command;

class PruneExpiredAcmeChallenges extends Command
{
    protected $signature = 'redi:prune-acme-challenges';

    protected $description = '删除已过期的 ACME 挑战行、空 token/响应体或畸形 HTTP-01 内容及对应节点同步记录';

    public function handle(): int
    {
        $expiredDomains = RediAcmeChallenge::query()
            ->where('expires_at', '<=', now())
            ->pluck('domain_name')
            ->unique()
            ->values();

        $deletedChallenges = RediAcmeChallenge::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $purgeSyncHosts = $expiredDomains->all();

        $invalidDomains = RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->where(function ($q) {
                $q->whereNull('token')->orWhere('token', '=', '')
                    ->orWhereNull('response_body')->orWhere('response_body', '=', '');
            })
            ->pluck('domain_name')
            ->unique()
            ->values();

        $deletedInvalid = RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->where(function ($q) {
                $q->whereNull('token')->orWhere('token', '=', '')
                    ->orWhereNull('response_body')->orWhere('response_body', '=', '');
            })
            ->delete();

        $purgeSyncHosts = array_merge($purgeSyncHosts, $invalidDomains->all());

        $malformedDomains = AcmeChallengeQuery::ready()
            ->get(['domain_name', 'token', 'response_body'])
            ->filter(function ($row) {
                return ! AcmeChallengeQuery::nodePublishableRow($row->domain_name, $row->token, $row->response_body);
            })
            ->pluck('domain_name')
            ->unique()
            ->values();

        $deletedMalformed = 0;
        if ($malformedDomains->isNotEmpty()) {
            $deletedMalformed = RediAcmeChallenge::query()
                ->whereIn('domain_name', $malformedDomains->all())
                ->delete();
            $purgeSyncHosts = array_merge($purgeSyncHosts, $malformedDomains->all());
        }

        if ($purgeSyncHosts !== []) {
            AcmeChallengeCleanup::purgeForHosts($purgeSyncHosts);
        }

        $this->info("pruned expired={$deletedChallenges}, invalid_empty={$deletedInvalid}, malformed={$deletedMalformed}");

        return self::SUCCESS;
    }
}
