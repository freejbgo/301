<?php

namespace App\Console\Commands;

use App\Models\RediAdminDomain;
use App\Services\DomainRecordNormalizer;
use App\Support\HostNameValidator;
use Illuminate\Console\Command;

class NormalizeDomainHosts extends Command
{
    protected $signature = 'redi:normalize-domain-hosts {--dry-run : 只报告不写入}';

    protected $description = '将 original_url 规范为 punycode ASCII，并修正可规范化的 skip_url';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        RediAdminDomain::query()->orderBy('id')->chunk(200, function ($rows) use ($dry, &$updated) {
            foreach ($rows as $domain) {
                $canonical = DomainRecordNormalizer::normalizeOriginalUrl($domain->original_url);
                if ($canonical === '' || ! HostNameValidator::isValidHostLabel($canonical)) {
                    $this->warn("跳过 id={$domain->id} 非法 original_url");
                    continue;
                }
                $skip = DomainRecordNormalizer::normalizeSkipUrl($domain->skip_url);
                $dirty = $canonical !== $domain->original_url
                    || $skip !== (string) $domain->skip_url;
                if (! $dirty) {
                    continue;
                }
                if ($skip === '' && trim((string) $domain->skip_url) !== '') {
                    $this->warn("id={$domain->id} skip_url 无效将清空（请尽快在后台补合法 http/https 链接）");
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

        $this->info($dry ? "dry-run: {$updated} 行待更新" : "已更新 {$updated} 行");

        return self::SUCCESS;
    }
}
