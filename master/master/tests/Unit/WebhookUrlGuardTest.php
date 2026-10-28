<?php

namespace Tests\Unit;

use App\Support\WebhookUrlGuard;
use Tests\TestCase;

class WebhookUrlGuardTest extends TestCase
{
    public function test_rejects_metadata_google_internal(): void
    {
        $this->assertFalse(WebhookUrlGuard::isSafeOutboundWebhookUrl('https://metadata.google.internal/'));
    }

    public function test_rejects_literal_private_ip(): void
    {
        $this->assertFalse(WebhookUrlGuard::isSafeOutboundWebhookUrl('http://10.0.0.1/hook'));
    }

    public function test_rejects_unresolvable_documentation_host(): void
    {
        $this->assertFalse(WebhookUrlGuard::isSafeOutboundWebhookUrl('https://hooks.example/hook'));
    }

    public function test_accepts_public_https_url_when_dns_resolves(): void
    {
        $this->assertTrue(WebhookUrlGuard::isSafeOutboundWebhookUrl('https://example.com/hook'));
    }
}
