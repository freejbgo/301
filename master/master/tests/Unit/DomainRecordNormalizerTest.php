<?php

namespace Tests\Unit;

use App\Services\DomainRecordNormalizer;
use PHPUnit\Framework\TestCase;

class DomainRecordNormalizerTest extends TestCase
{
    public function test_normalize_original_url_strips_scheme(): void
    {
        $this->assertSame('foo.com', DomainRecordNormalizer::normalizeOriginalUrl('https://Foo.com/'));
    }

    public function test_rejects_non_http_redirect(): void
    {
        $this->assertFalse(DomainRecordNormalizer::isAllowedRedirectUrl('javascript:alert(1)'));
    }

    public function test_rejects_userinfo_in_redirect_url(): void
    {
        $this->assertFalse(DomainRecordNormalizer::isAllowedRedirectUrl('https://evil@trusted.example/'));
    }

    public function test_original_url_hash_is_stable(): void
    {
        $hash = DomainRecordNormalizer::originalUrlHash('https://Example.com/');
        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, DomainRecordNormalizer::originalUrlHash('example.com'));
    }
}
