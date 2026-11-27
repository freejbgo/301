<?php

namespace Tests\Unit;

use App\Support\PlatformBranding;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PlatformBrandingStorageTest extends TestCase
{
    public function test_normalize_storage_path_rejects_traversal(): void
    {
        $method = new ReflectionMethod(PlatformBranding::class, 'normalizeStoragePath');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke(null, '../secrets/logo.png'));
        $this->assertSame('', $method->invoke(null, 'branding/shell.php'));
        $this->assertSame('branding/logo.png', $method->invoke(null, 'branding/logo.png'));
        $this->assertSame('branding/icon.ico', $method->invoke(null, 'branding/icon.ico'));
    }
}
