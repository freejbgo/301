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

    public function test_rejects_crlf_in_path(): void
    {
        $this->assertFalse(SafeRelativeRedirect::isSafe("/admin\r\n/evil"));
    }

    public function test_rejects_null_and_tab_in_path(): void
    {
        $this->assertFalse(SafeRelativeRedirect::isSafe("/admin\x00/evil"));
        $this->assertFalse(SafeRelativeRedirect::isSafe("/admin\t/evil"));
    }

    public function test_accepts_same_app_absolute_url_from_admin_url_helper(): void
    {
        config(['app.url' => 'https://master.example.com']);
        $absolute = 'https://master.example.com/admin/nodes';
        $this->assertTrue(SafeRelativeRedirect::isSafe($absolute));
        $this->assertSame('/admin/nodes', SafeRelativeRedirect::normalizeForRedirect($absolute));
    }

    public function test_rejects_off_site_absolute_url(): void
    {
        config(['app.url' => 'https://master.example.com']);
        $this->assertFalse(SafeRelativeRedirect::isSafe('https://evil.example/phish'));
        $this->assertNull(SafeRelativeRedirect::normalizeForRedirect('https://evil.example/phish'));
    }
}
