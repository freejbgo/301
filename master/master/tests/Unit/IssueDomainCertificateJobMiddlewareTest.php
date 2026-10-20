<?php

namespace Tests\Unit;

use App\Jobs\IssueDomainCertificateJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class IssueDomainCertificateJobMiddlewareTest extends TestCase
{
    public function test_registers_global_and_per_domain_without_overlapping(): void
    {
        $job = new IssueDomainCertificateJob(99);
        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);

        $global = $this->lockName($middleware[0]);
        $perDomain = $this->lockName($middleware[1]);

        $this->assertSame('redi-acme-le-global', $global);
        $this->assertSame('redi-acme-domain-99', $perDomain);
    }

    private function lockName(WithoutOverlapping $middleware): string
    {
        $ref = new \ReflectionClass($middleware);
        $prop = $ref->getProperty('key');
        $prop->setAccessible(true);

        return (string) $prop->getValue($middleware);
    }
}
