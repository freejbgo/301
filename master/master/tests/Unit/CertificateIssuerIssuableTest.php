<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Services\Acme\CertificateIssuer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
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
        $this->expectExceptionMessage('Invalid domain hostname');

        app(CertificateIssuer::class)->issue($cert);
    }

    public function test_run_acme_issue_stores_pem_after_the_refreshing_check(): void
    {
        $ref = new ReflectionMethod(CertificateIssuer::class, 'runAcmeIssue');
        $lines = array_slice(
            file($ref->getFileName()),
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        );
        $body = implode('', $lines);
        $assertAt = strpos($body, 'assertDomainIssuable');
        $pemAt = strpos($body, 'setCertificatePem');

        $this->assertNotFalse($assertAt);
        $this->assertNotFalse($pemAt);
        $this->assertLessThan($pemAt, $assertAt);
    }

    public function test_pem_saved_after_issuable_check_is_still_readable(): void
    {
        Schema::table('redi_domain_certs', function (Blueprint $table) {
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('enabled')->default(1);
        });
        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
        });

        $domainId = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'aa.example.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cert = RediDomainCert::query()->create([
            'domain_id' => $domainId,
            'domain_name' => 'aa.example.com',
            'status' => RediDomainCert::STATUS_VALIDATING,
        ]);

        $assert = new ReflectionMethod(CertificateIssuer::class, 'assertDomainIssuable');
        $assert->setAccessible(true);
        $assert->invoke(app(CertificateIssuer::class), $cert);

        $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n";
        $key = "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n";
        $cert->setCertificatePem($pem);
        $cert->setPrivateKeyPem($key);
        $cert->status = RediDomainCert::STATUS_ISSUED;
        $cert->save();

        $fresh = $cert->fresh();
        $this->assertSame($pem, $fresh->getCertificatePem());
        $this->assertSame($key, $fresh->getPrivateKeyPem());
        $this->assertTrue(in_array($fresh->status, [
            RediDomainCert::STATUS_ISSUED,
            RediDomainCert::STATUS_DEPLOYING,
        ], true));
    }
}
