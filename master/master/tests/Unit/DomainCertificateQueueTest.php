<?php

namespace Tests\Unit;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediAdminDomain;
use App\Services\DomainCertificateQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainCertificateQueueTest extends TestCase
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

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id');
            $table->string('admin_name')->nullable();
            $table->string('original_url');
            $table->string('original_url_hash', 64)->nullable();
            $table->string('skip_url')->default('https://example.com');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('skip_num')->default(0);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        RediAdminDomain::query()->insert([
            [
                'id' => 1,
                'admin_id' => 10,
                'original_url' => 'a.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'admin_id' => 20,
                'original_url' => 'b.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_dispatch_owned_skips_other_tenant_domains(): void
    {
        Bus::fake();

        $count = DomainCertificateQueue::dispatchOwned([1, 2], 20, false);

        $this->assertSame(1, $count);
        Bus::assertDispatched(IssueDomainCertificateJob::class, function (IssueDomainCertificateJob $job) {
            return $job->domainId === 2;
        });
        Bus::assertDispatchedTimes(IssueDomainCertificateJob::class, 1);
    }

    public function test_dispatch_owned_skips_disabled_domains(): void
    {
        Bus::fake();

        \DB::table('redi_admin_domain')->insert([
            'id' => 3,
            'admin_id' => 10,
            'original_url' => 'off.com',
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DomainCertificateQueue::dispatchOwned([1, 3], 10, false);

        $this->assertSame(1, $count);
        Bus::assertDispatchedTimes(IssueDomainCertificateJob::class, 1);
    }
}
