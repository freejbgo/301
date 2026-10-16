<?php

namespace App\Http\Middleware;

use Closure;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;

class RequireAdminSessionForDcatApi
{
    public function handle(Request $request, Closure $next)
    {
        $prefix = trim((string) config('admin.route.prefix', 'admin'), '/');
        if (! $request->is($prefix.'/dcat-api/*')) {
            return $next($request);
        }

        if (! Admin::guard()->check()) {
            abort(401, 'Unauthorized');
        }

        return $next($request);
    }
}
