<?php

namespace Tests\Unit;

use App\Jobs\IssueDomainCertificateJob;
use App\Models\RediDomainCert;
use App\Services\Acme\CertificateIssuer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IssueDomainCertificateJobTest extends TestCase
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
            $table->unsignedInteger('admin_id')->default(1);
            $table->string('original_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->unsignedInteger('admin_id')->nullable();
            $table->string('domain_name')->default('');
            $table->string('status')->default('none');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function test_invalid_original_url_does_not_persist_ipv4_as_domain_name(): void
    {
        $domainId = (int) \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issuer = $this->createMock(CertificateIssuer::class);
        $issuer->expects($this->never())->method('issue');

        (new IssueDomainCertificateJob($domainId))->handle($issuer);

        $cert = RediDomainCert::query()->where('domain_id', $domainId)->first();
        $this->assertNotNull($cert);
        $this->assertSame('', $cert->domain_name);
        $this->assertSame(RediDomainCert::STATUS_FAILED, $cert->status);
        $this->assertStringContainsString('无效', (string) $cert->last_error);
    }
}
