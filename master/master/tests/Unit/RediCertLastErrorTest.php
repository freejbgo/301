<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Support\RediCertLastError;
use App\Support\RediErrorMessage;
use Tests\TestCase;

class RediCertLastErrorTest extends TestCase
{
    public function test_matches_by_code_and_legacy_message(): void
    {
        $cert = new RediDomainCert();
        $cert->last_error_code = RediCertLastError::DOMAIN_DISABLED_CANCEL;
        $cert->last_error = RediErrorMessage::get(RediCertLastError::DOMAIN_DISABLED_CANCEL);

        $this->assertTrue(RediCertLastError::matches($cert, RediCertLastError::DOMAIN_DISABLED_CANCEL));

        $legacy = new RediDomainCert();
        $legacy->last_error = RediCertLastError::LEGACY_DOMAIN_DISABLED_CANCEL_MSG;

        $this->assertTrue(RediCertLastError::matches($legacy, RediCertLastError::DOMAIN_DISABLED_CANCEL));
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
