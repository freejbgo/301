<?php

namespace Tests\Unit;

use App\Support\AcmeHttp01Token;
use InvalidArgumentException;
use Tests\TestCase;

class AcmeHttp01TokenTest extends TestCase
{
    public function test_normalize_accepts_base64url_token(): void
    {
        $this->assertSame('abc-123_XYZ', AcmeHttp01Token::normalize('abc-123_XYZ'));
    }

    public function test_normalize_rejects_path_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AcmeHttp01Token::normalize('../evil');
    }

    public function test_is_valid_rejects_control_chars_before_trim(): void
    {
        $this->assertFalse(AcmeHttp01Token::isValid("ok\n"));
    }

    public function test_normalize_rejects_control_chars_before_trim(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AcmeHttp01Token::normalize("ok\n");
    }
}
