<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Services\Acme\CertificateIssuer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CertificateIssuerIssuableTest extends TestCase
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
            $table->string('domain_name');
            $table->string('status')->default('none');
            $table->timestamps();
        });
    }

    public function test_issue_rejects_invalid_domain_host(): void
    {
        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cert = RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => '1.2.3.4',
            'status' => RediDomainCert::STATUS_NONE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('域名主机名无效');

        app(CertificateIssuer::class)->issue($cert);
    }
}
