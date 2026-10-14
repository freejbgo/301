<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TrustedProxiesProductionTest extends TestCase
{
    public function test_production_rejects_trust_all_proxies_without_ack(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new \Illuminate\Config\Repository([
            'app' => ['env' => 'production'],
            'trustedproxy' => ['proxies' => '*'],
        ]));
        putenv('REDI_ALLOW_TRUST_ALL_PROXIES=false');

        $provider = new AppServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'warnIfTrustingAllProxiesInProduction');

        $this->expectException(RuntimeException::class);
        $method->invoke($provider);
    }
}
