<?php

namespace App\Console\Commands;

use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use App\Services\AcmeChallengeQuery;
use Illuminate\Console\Command;

class PruneExpiredAcmeChallenges extends Command
{
    protected $signature = 'redi:prune-acme-challenges';

    protected $description = '删除已过期的 ACME 挑战行、无效空 token 行及对应节点同步记录';

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

        $deletedSync = 0;
        if ($expiredDomains->isNotEmpty()) {
            $deletedSync = RediAcmeChallengeNodeSync::query()
                ->whereIn('domain_name', $expiredDomains->all())
                ->delete();
        }

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

        if ($invalidDomains->isNotEmpty()) {
            $deletedSync += (int) RediAcmeChallengeNodeSync::query()
                ->whereIn('domain_name', $invalidDomains->all())
                ->delete();
        }

        $malformedDomains = AcmeChallengeQuery::ready()
            ->get(['domain_name', 'token', 'response_body'])
            ->filter(function ($row) {
                return ! AcmeChallengeQuery::http01ContentValid($row->token, $row->response_body);
            })
            ->pluck('domain_name')
            ->unique()
            ->values();

        $deletedMalformed = 0;
        if ($malformedDomains->isNotEmpty()) {
            $deletedMalformed = RediAcmeChallenge::query()
                ->whereIn('domain_name', $malformedDomains->all())
                ->delete();
            $deletedSync += (int) RediAcmeChallengeNodeSync::query()
                ->whereIn('domain_name', $malformedDomains->all())
                ->delete();
        }

        $this->info("pruned expired={$deletedChallenges}, invalid_empty={$deletedInvalid}, malformed={$deletedMalformed}, sync_rows={$deletedSync}");

        return self::SUCCESS;
    }
}
