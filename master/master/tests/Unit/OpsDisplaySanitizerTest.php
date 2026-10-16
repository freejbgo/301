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
        $this->assertSame('ab', OpsDisplaySanitizer::stripControlChars("a\tb"));
    }

    public function test_log_snippet_truncates(): void
    {
        $this->assertSame('ab', OpsDisplaySanitizer::logSnippet("a\r\nb", 10));
        $this->assertSame(501, mb_strlen(OpsDisplaySanitizer::logSnippet(str_repeat('z', 600), 500)));
    }

    public function test_contains_control_chars(): void
    {
        $this->assertTrue(OpsDisplaySanitizer::containsControlChars("a\tb"));
        $this->assertFalse(OpsDisplaySanitizer::containsControlChars('ab'));
    }

    public function test_sanitize_health_issues(): void
    {
        $out = OpsDisplaySanitizer::sanitizeHealthIssues([
            ['level' => 'warning', 'code' => 'x', 'message' => "a\r\nb"],
        ]);
        $this->assertSame('ab', $out[0]['message']);
    }
}
