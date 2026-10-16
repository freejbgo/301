<?php

namespace Tests\Unit;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediDomainCert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RenewDomainCertificatesCommandTest extends TestCase
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
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_renew_queues_jobs_for_expiring_active_certs(): void
    {
        Bus::fake();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'soon.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'soon.example',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('redi:renew-certificates', ['--days' => 25]));
        $this->assertStringContainsString('queued: 1 cert(s)', Artisan::output());

        Bus::assertDispatched(IssueDomainCertificateJob::class, function (IssueDomainCertificateJob $job) use ($domainId) {
            return $job->domainId === $domainId;
        });
    }

    public function test_renew_reports_when_no_certs_due(): void
    {
        Bus::fake();

        $this->assertSame(0, Artisan::call('redi:renew-certificates', ['--days' => 25]));
        $this->assertStringContainsString('没有需要续期的证书', Artisan::output());

        Bus::assertNothingDispatched();
    }

    public function test_renew_dry_run_does_not_dispatch(): void
    {
        Bus::fake();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'dry.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'dry.example',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'expires_at' => now()->addDays(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:renew-certificates', ['--days' => 25, '--dry-run' => true])
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_renew_skips_recent_validating_cert(): void
    {
        Bus::fake();

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'validating.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'validating.example',
            'status' => RediDomainCert::STATUS_VALIDATING,
            'expires_at' => now()->addDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('redi:renew-certificates', ['--days' => 25])
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_renew_queues_stuck_validating_cert(): void
    {
        Bus::fake();
        config(['redi.renew_validating_stuck_hours' => 3]);

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'stuck.example',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $domainId,
            'domain_name' => 'stuck.example',
            'status' => RediDomainCert::STATUS_VALIDATING,
            'expires_at' => now()->addDays(10),
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

        $this->artisan('redi:renew-certificates', ['--days' => 25])
            ->assertExitCode(0);

        Bus::assertDispatched(IssueDomainCertificateJob::class);
    }
}
