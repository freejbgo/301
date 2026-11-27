<?php

namespace Tests\Unit;

use App\Support\FailedQueueFailureSummary;
use Tests\TestCase;

class FailedQueueFailureSummaryTest extends TestCase
{
    public function test_summary_keeps_the_first_line_and_drops_key_material(): void
    {
        $exception = "AcmeException: DNS problem: NXDOMAIN looking up A for cc.example.com in /var/www/master/vendor/acme.php:88\n"
            ."Stack trace:\n"
            ."-----BEGIN PRIVATE KEY-----\nSECRET\n-----END PRIVATE KEY-----\n";

        $summary = FailedQueueFailureSummary::fromException($exception);

        $this->assertSame('AcmeException: DNS problem: NXDOMAIN looking up A for cc.example.com', $summary);
        $this->assertStringNotContainsString('PRIVATE KEY', $summary);
        $this->assertStringNotContainsString('SECRET', $summary);
    }

    public function test_job_name_uses_the_class_basename(): void
    {
        $payload = json_encode([
            'displayName' => 'App\\Jobs\\IssueDomainCertificateJob',
        ]);

        $this->assertSame(
            'IssueDomainCertificateJob',
            FailedQueueFailureSummary::jobName((string) $payload)
        );
    }
}
