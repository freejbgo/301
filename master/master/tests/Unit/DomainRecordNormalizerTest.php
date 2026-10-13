<?php

namespace Tests\Unit;

use App\Services\DomainRecordNormalizer;
use PHPUnit\Framework\TestCase;

class DomainRecordNormalizerTest extends TestCase
{
    public function test_normalize_original_url_strips_scheme(): void
    {
        $this->assertSame('foo.com', DomainRecordNormalizer::normalizeOriginalUrl('https://foo.com/'));
    }

    public function test_rejects_non_http_redirect(): void
    {
        $this->assertFalse(DomainRecordNormalizer::isAllowedRedirectUrl('javascript:alert(1)'));
    }
}
