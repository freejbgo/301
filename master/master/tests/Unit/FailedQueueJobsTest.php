<?php

namespace Tests\Unit;

use App\Services\FailedQueueJobs;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FailedQueueJobsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        config(['queue.failed.driver' => 'database-uuids']);
        config(['queue.failed.database' => 'sqlite']);
        config(['queue.connections.database.connection' => 'sqlite']);
        $this->app->forgetInstance('queue.failer');
        $this->app->forgetInstance('queue');

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_retry_all_clears_failed_jobs_and_requeues_them(): void
    {
        $uuid = '11111111-1111-1111-1111-111111111111';
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\IssueDomainCertificateJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'attempts' => 3,
                'data' => [],
            ]),
            'exception' => "RuntimeException: boom in /opt/301/master/app/Jobs/IssueDomainCertificateJob.php:170\n",
            'failed_at' => now(),
        ]);

        $count = FailedQueueJobs::retryAll();

        $this->assertSame(1, $count);
        $this->assertSame(0, (int) DB::table('failed_jobs')->count());
        $this->assertSame(1, (int) DB::table('jobs')->count());

        $recent = FailedQueueJobs::recent();
        $this->assertSame([], $recent);
    }

    public function test_recent_lists_the_exception_summary(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\IssueDomainCertificateJob',
            ]),
            'exception' => "AcmeException: rate limited\nStack trace:\n#0 hidden",
            'failed_at' => '2026-11-27 12:00:00',
        ]);

        $recent = FailedQueueJobs::recent();

        $this->assertCount(1, $recent);
        $this->assertSame('IssueDomainCertificateJob', $recent[0]['job']);
        $this->assertSame('AcmeException: rate limited', $recent[0]['summary']);
        $this->assertSame('2026-11-27 12:00:00', $recent[0]['failed_at']);
    }
}
