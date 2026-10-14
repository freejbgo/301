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
            if (! filter_var(env('REDI_ALLOW_TRUST_ALL_PROXIES', false), FILTER_VALIDATE_BOOLEAN)) {
                throw new \RuntimeException(
                    'TRUSTED_PROXIES=* is not allowed in production. Set explicit proxy IPs/CIDRs, or set REDI_ALLOW_TRUST_ALL_PROXIES=true only when the app is never directly exposed.'
                );
            }
            \Illuminate\Support\Facades\Log::warning(
                'TRUSTED_PROXIES=* in production with REDI_ALLOW_TRUST_ALL_PROXIES=true; X-Forwarded-For may be forged if clients can reach the app directly.'
            );
        }
    }
}
