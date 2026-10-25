<?php

namespace App\Console\Commands;

use App\Models\RediAdminDomain;
use App\Services\DomainRecordNormalizer;
use App\Support\RediErrorMessage;
use Illuminate\Console\Command;

class NormalizeDomainHosts extends Command
{
    protected $signature = 'redi:normalize-domain-hosts {--dry-run : Report only; do not write}';

    protected $description = 'Normalize original_url to punycode ASCII and fix skip_url when possible';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        RediAdminDomain::query()->orderBy('id')->chunk(200, function ($rows) use ($dry, &$updated) {
            foreach ($rows as $domain) {
                $canonical = DomainRecordNormalizer::originalUrlIfValid($domain->original_url);
                if ($canonical === '') {
                    $this->warn(RediErrorMessage::get('cli.normalize_skip_invalid', ['id' => $domain->id]));
                    continue;
                }
                $skip = DomainRecordNormalizer::normalizeSkipUrl($domain->skip_url);
                $dirty = $canonical !== $domain->original_url
                    || $skip !== (string) $domain->skip_url;
                if (! $dirty) {
                    continue;
                }
                if ($skip === '' && trim((string) $domain->skip_url) !== '') {
                    $this->warn(RediErrorMessage::get('cli.normalize_skip_url_clear', ['id' => $domain->id]));
                }
                if ($dry) {
                    $this->line("would update id={$domain->id} {$canonical}, skip_url -> {$skip}");
                    $updated++;
                    continue;
                }
                $domain->original_url = $canonical;
                $domain->original_url_hash = DomainRecordNormalizer::originalUrlHash($canonical);
                $domain->skip_url = $skip;
                RediAdminDomain::withoutEvents(function () use ($domain) {
                    $domain->save();
                });
                $updated++;
            }
        });

        $this->info(RediErrorMessage::get('cli.normalize_done', [
            'action' => $dry ? RediErrorMessage::get('cli.normalize_dry') : RediErrorMessage::get('cli.normalize_updated'),
            'count' => $updated,
        ]));

        return self::SUCCESS;
    }
}
