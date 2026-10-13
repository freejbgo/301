<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs or CIDRs, or "*" to trust all forwarded headers.
    | Used by App\Http\Middleware\TrustProxies (config:cache safe).
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
