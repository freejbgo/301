<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (! $this->app->runningInConsole()) {
            $this->app->booted(function () {
                \App\Support\PlatformBranding::applyLoginPageName();
            });
            $this->warnIfTrustingAllProxiesInProduction();
        }
    }

    protected function warnIfTrustingAllProxiesInProduction(): void
    {
        if (config('app.env') !== 'production') {
            return;
        }
        $raw = config('trustedproxy.proxies');
        if ($raw === '*' || $raw === '**') {
            \Illuminate\Support\Facades\Log::warning(
                'TRUSTED_PROXIES=* in production allows forged X-Forwarded-For; set explicit proxy IPs unless the app is never directly exposed.'
            );
        }
    }
}
