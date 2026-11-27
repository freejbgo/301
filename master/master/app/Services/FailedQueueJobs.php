<?php

namespace App\Services;

use App\Support\FailedQueueFailureSummary;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FailedQueueJobs
{
    /**
     * @return list<array{job: string, summary: string, failed_at: string}>
     */
    public static function recent(int $limit = 5): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $limit = max(1, min($limit, 20));
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit($limit)->get();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'job' => FailedQueueFailureSummary::jobName((string) ($row->payload ?? '')),
                'summary' => FailedQueueFailureSummary::fromException((string) ($row->exception ?? '')),
                'failed_at' => (string) ($row->failed_at ?? ''),
            ];
        }

        return $out;
    }

    public static function retryAll(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        $before = (int) DB::table('failed_jobs')->count();
        if ($before === 0) {
            return 0;
        }

        // 在已经启动的主控进程里调用，不经过 shell，也不查找 php / artisan 路径。
        Artisan::call('queue:retry', ['id' => ['all']]);

        $moved = $before - (int) DB::table('failed_jobs')->count();
        if ($moved <= 0) {
            throw new RuntimeException('failed_jobs 仍有 '.$before.' 条，重试没有写回队列');
        }

        return $moved;
    }
}
