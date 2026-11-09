<?php

namespace App\Console\Commands;

use App\Support\PlatformBranding;
use App\Support\RediErrorMessage;
use Illuminate\Console\Command;

class NormalizePlatformBranding extends Command
{
    protected $signature = 'redi:normalize-platform-branding {--dry-run : Report only; do not write}';

    protected $description = 'Migrate flat platform_branding JSON (top-level titles) into locales.zh_CN';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = PlatformBranding::migrateFlatStoredToLocales($dry);

        $key = match ($result['status']) {
            'migrated' => 'cli.branding_migrate_done',
            'dry_run' => 'cli.branding_migrate_would',
            'already_locales' => 'cli.branding_migrate_already',
            default => 'cli.branding_migrate_none',
        };
        $this->info(RediErrorMessage::get($key, ['status' => $result['status']]));

        return ($result['migrated'] || in_array($result['status'], ['already_locales', 'empty', 'nothing_to_migrate'], true))
            ? self::SUCCESS
            : self::FAILURE;
    }
}
