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
            'redi' => ['allow_trust_all_proxies' => false],
        ]));

        $provider = new AppServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'warnIfTrustingAllProxiesInProduction');

        $this->expectException(RuntimeException::class);
        $method->invoke($provider);
    }

    public function test_production_allows_trust_all_when_config_ack(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new \Illuminate\Config\Repository([
            'app' => ['env' => 'production'],
            'trustedproxy' => ['proxies' => '*'],
            'redi' => ['allow_trust_all_proxies' => true],
        ]));

        $provider = new AppServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'warnIfTrustingAllProxiesInProduction');
        $method->invoke($provider);
        $this->addToAssertionCount(1);
    }
}
