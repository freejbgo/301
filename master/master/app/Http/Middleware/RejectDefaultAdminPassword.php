<?php

namespace App\Http\Middleware;

use Closure;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RejectDefaultAdminPassword
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('redi.block_default_admin_password', true)) {
            return $next($request);
        }

        $user = Admin::user();
        if (! $user) {
            return $next($request);
        }

        $prefix = trim((string) config('admin.route.prefix', 'admin'), '/');
        $allowed = [
            $prefix.'/auth/setting',
            $prefix.'/auth/setting/*',
            $prefix.'/auth/logout',
            $prefix.'/locale',
            $prefix.'/assets/*',
        ];
        foreach ($allowed as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        $password = method_exists($user, 'getAuthPassword')
            ? $user->getAuthPassword()
            : ($user->password ?? null);
        if (! $password) {
            return $next($request);
        }

        foreach ((array) config('redi.known_default_admin_passwords', []) as $plain) {
            $plain = (string) $plain;
            if ($plain !== '' && Hash::check($plain, $password)) {
                admin_toastr(__('redi-auth.default_password_blocked'), 'error', ['timeOut' => 0]);

                return redirect(admin_url('auth/setting'));
            }
        }

        return $next($request);
    }
}
