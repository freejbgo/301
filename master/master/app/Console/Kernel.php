<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // CentOS 生产环境由 /etc/cron.d/redi-renew 调用 artisan（含 08:30 redi:health）。
        // 勿再配置 schedule:run，以免与 Cron 重复执行续期/清理。
        $schedule->command('redi:renew-certificates')
            ->dailyAt('03:10')
            ->withoutOverlapping(120);
        $schedule->command('redi:prune-acme-challenges')
            ->dailyAt('04:10')
            ->withoutOverlapping(30);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
