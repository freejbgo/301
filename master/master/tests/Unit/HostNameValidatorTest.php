<?php

namespace Tests\Unit;

use App\Support\HostNameValidator;
use Tests\TestCase;

class HostNameValidatorTest extends TestCase
{
    public function test_accepts_simple_hostnames(): void
    {
        $this->assertTrue(HostNameValidator::isValidHostLabel('example.com'));
        $this->assertTrue(HostNameValidator::isValidHostLabel('a.b.co.uk'));
    }

    public function test_canonical_ascii_idn_when_intl_available(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('intl extension not available');
        }
        $ascii = HostNameValidator::toCanonicalAscii('münchen.de');
        $this->assertStringStartsWith('xn--', $ascii);
    }

    public function test_to_canonical_ascii_rejects_control_chars(): void
    {
        $this->assertSame('', HostNameValidator::toCanonicalAscii("foo\r\n.com"));
    }

    public function test_rejects_ip_path_and_garbage(): void
    {
        $this->assertFalse(HostNameValidator::isValidHostLabel(''));
        $this->assertFalse(HostNameValidator::isValidHostLabel('1.2.3.4'));
        $this->assertFalse(HostNameValidator::isValidHostLabel('foo.com/bar'));
        $this->assertFalse(HostNameValidator::isValidHostLabel("evil\n.com"));
    }
}
