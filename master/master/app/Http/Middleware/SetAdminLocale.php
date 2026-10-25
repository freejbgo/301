<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetAdminLocale
{
    public const SESSION_KEY = 'admin_locale';

    /** @return list<string> */
    public static function supported(): array
    {
        return config('redi.admin_locales', ['zh_CN', 'zh_TW', 'en']);
    }

    public static function resolve(?string $preferred = null): string
    {
        $locale = $preferred ?? session(self::SESSION_KEY) ?? config('app.locale', 'zh_CN');
        $supported = self::supported();

        if (! in_array($locale, $supported, true)) {
            return $supported[0] ?? 'zh_CN';
        }

        return $locale;
    }

    public function handle(Request $request, Closure $next)
    {
        $prefix = trim((string) config('admin.route.prefix', 'admin'), '/');
        if ($prefix !== '' && ! $request->is($prefix, $prefix.'/*')) {
            return $next($request);
        }

        app()->setLocale(self::resolve());

        return $next($request);
    }
}
