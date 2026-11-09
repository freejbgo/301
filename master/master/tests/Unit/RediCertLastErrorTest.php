<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Support\RediCertLastError;
use App\Support\RediErrorMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediCertLastErrorTest extends TestCase
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

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('last_error_code')->nullable();
            $table->text('last_error')->nullable();
        });
    }

    public function test_matches_by_code_and_localized_message(): void
    {
        $cert = new RediDomainCert();
        $cert->last_error_code = RediCertLastError::DOMAIN_DISABLED_CANCEL;
        $cert->last_error = RediErrorMessage::get(RediCertLastError::DOMAIN_DISABLED_CANCEL);

        $this->assertTrue(RediCertLastError::matches($cert, RediCertLastError::DOMAIN_DISABLED_CANCEL));

        $byMessage = new RediDomainCert();
        $byMessage->last_error = RediErrorMessage::get(RediCertLastError::DOMAIN_DISABLED_CANCEL);

        $this->assertTrue(RediCertLastError::matches($byMessage, RediCertLastError::DOMAIN_DISABLED_CANCEL));
    }

    public function test_declared_codes_resolve_in_redi_errors(): void
    {
        foreach (RediCertLastError::declaredCodes() as $code) {
            $message = RediCertLastError::messageForCode($code);
            $this->assertNotSame('', $message);
            $this->assertSame($message, RediErrorMessage::get($code));
        }
    }
}
