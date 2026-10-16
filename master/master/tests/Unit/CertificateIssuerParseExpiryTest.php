<?php

namespace Tests\Unit;

use App\Services\Acme\CertificateIssuer;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class CertificateIssuerParseExpiryTest extends TestCase
{
    public function test_parse_cert_expiry_reads_openssl_generated_leaf(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['CN' => 'localhost'], $key);
        $this->assertNotFalse($csr);
        $x509 = openssl_csr_sign($csr, null, $key, 30);
        $this->assertNotFalse($x509);
        openssl_x509_export($x509, $pem);

        $issuer = new CertificateIssuer();
        $method = new ReflectionMethod($issuer, 'parseCertExpiry');
        $method->setAccessible(true);
        $expiry = $method->invoke($issuer, $pem);

        $this->assertInstanceOf(Carbon::class, $expiry);
        $this->assertTrue($expiry->isFuture());
    }

    public function test_parse_cert_expiry_returns_null_for_garbage(): void
    {
        $issuer = new CertificateIssuer();
        $method = new ReflectionMethod($issuer, 'parseCertExpiry');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($issuer, 'not-a-pem'));
    }
}
