<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class TrustedProxiesProductionTest extends TestCase
{
    public function test_production_rejects_trust_all_proxies_without_ack(): void
    {
        config(['app.env' => 'production']);
        config(['trustedproxy.proxies' => '*']);
        config(['redi.allow_trust_all_proxies' => false]);

        $provider = new AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'warnIfTrustingAllProxiesInProduction');

        $this->expectException(RuntimeException::class);
        $method->invoke($provider);
    }

    public function test_production_allows_trust_all_when_config_ack(): void
    {
        Log::fake();
        config(['app.env' => 'production']);
        config(['trustedproxy.proxies' => '*']);
        config(['redi.allow_trust_all_proxies' => true]);

        $provider = new AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'warnIfTrustingAllProxiesInProduction');
        $method->invoke($provider);

        Log::assertLogged(function ($log) {
            return $log->level === 'warning'
                && str_contains($log->message, 'TRUSTED_PROXIES=*');
        });
    }
}
