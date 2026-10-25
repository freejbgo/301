<?php

namespace App\Console\Commands;

use App\Models\RediAcmeChallenge;
use App\Services\AcmeChallengeCleanup;
use App\Services\AcmeChallengeQuery;
use Illuminate\Console\Command;

class PruneExpiredAcmeChallenges extends Command
{
    protected $signature = 'redi:prune-acme-challenges';

    protected $description = 'Prune expired ACME challenges, empty tokens, malformed HTTP-01 rows, and node sync rows';

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

        $malformedRows = AcmeChallengeQuery::ready()
            ->get(['id', 'domain_name', 'token', 'response_body'])
            ->filter(function ($row) {
                return ! AcmeChallengeQuery::nodePublishableRow($row->domain_name, $row->token, $row->response_body);
            })
            ->values();

        $deletedMalformed = 0;
        if ($malformedRows->isNotEmpty()) {
            $deletedMalformed = RediAcmeChallenge::query()
                ->whereIn('id', $malformedRows->pluck('id')->all())
                ->delete();
            $purgeSyncHosts = array_merge($purgeSyncHosts, $malformedRows->pluck('domain_name')->all());
        }

        if ($purgeSyncHosts !== []) {
            AcmeChallengeCleanup::purgeForHosts($purgeSyncHosts);
        }

        $this->info("pruned expired={$deletedChallenges}, invalid_empty={$deletedInvalid}, malformed={$deletedMalformed}");

        return self::SUCCESS;
    }
}
