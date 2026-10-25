<?php

namespace Tests\Unit;

use App\Support\RediErrorMessage;
use Tests\TestCase;

class RediErrorMessageTest extends TestCase
{
    public function test_returns_english_canonical_message(): void
    {
        app()->setLocale('zh_CN');

        $this->assertSame(
            'Invalid domain hostname; cannot issue certificate',
            RediErrorMessage::get('cert.invalid_hostname')
        );
    }
}
