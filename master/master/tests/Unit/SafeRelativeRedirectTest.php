<?php

namespace Tests\Unit;

use App\Support\SafeRelativeRedirect;
use Tests\TestCase;

class SafeRelativeRedirectTest extends TestCase
{
    public function test_accepts_admin_relative_path(): void
    {
        $this->assertTrue(SafeRelativeRedirect::isSafe('/admin/nodes'));
    }

    public function test_rejects_protocol_relative_url(): void
    {
        $this->assertFalse(SafeRelativeRedirect::isSafe('//evil.example/phish'));
    }

    public function test_rejects_path_without_leading_slash(): void
    {
        $this->assertFalse(SafeRelativeRedirect::isSafe('admin/nodes'));
    }

    public function test_rejects_empty(): void
    {
        $this->assertFalse(SafeRelativeRedirect::isSafe(''));
        $this->assertFalse(SafeRelativeRedirect::isSafe(null));
    }

    public function test_rejects_backslash_in_path(): void
    {
        $this->assertFalse(SafeRelativeRedirect::isSafe('/admin\\nodes'));
    }
}
