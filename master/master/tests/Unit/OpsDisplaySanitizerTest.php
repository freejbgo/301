<?php

namespace Tests\Unit;

use App\Support\OpsDisplaySanitizer;
use PHPUnit\Framework\TestCase;

class OpsDisplaySanitizerTest extends TestCase
{
    public function test_field_strips_control_chars_and_truncates(): void
    {
        $this->assertSame('evilnode', OpsDisplaySanitizer::field("evil\r\nnode"));
        $long = str_repeat('a', 130);
        $this->assertSame(121, mb_strlen(OpsDisplaySanitizer::field($long)));
        $this->assertStringEndsWith('…', OpsDisplaySanitizer::field($long));
    }

    public function test_strip_control_chars(): void
    {
        $this->assertSame('ab', OpsDisplaySanitizer::stripControlChars("a\r\nb"));
    }
}
