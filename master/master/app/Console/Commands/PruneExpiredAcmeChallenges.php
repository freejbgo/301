<?php

namespace App\Console\Commands;

use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use Illuminate\Console\Command;

class PruneExpiredAcmeChallenges extends Command
{
    protected $signature = 'redi:prune-acme-challenges';

    protected $description = '删除已过期的 ACME 挑战行、无效空 token 行及对应节点同步记录';

    public function handle(): int
    {
        $expiredDomains = RediAcmeChallenge::query()
            ->where('expires_at', '<=', now())
            ->pluck('domain_name');

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
            ->pluck('domain_name');

        $deletedInvalid = RediAcmeChallenge::query()
            ->where('expires_at', '>', now())
            ->where(function ($q) {
                $q->whereNull('token')->orWhere('token', '=', '')
                    ->orWhereNull('response_body')->orWhere('response_body', '=', '');
            })
            ->delete();

        if ($invalidDomains->isNotEmpty()) {
            RediAcmeChallengeNodeSync::query()
                ->whereIn('domain_name', $invalidDomains->all())
                ->delete();
        }

        $this->info("pruned expired={$deletedChallenges}, invalid_empty={$deletedInvalid}, sync_rows={$deletedSync}");

        return self::SUCCESS;
    }
}
