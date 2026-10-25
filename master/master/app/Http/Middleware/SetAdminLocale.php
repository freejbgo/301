<?php

namespace App\Http\Middleware;

use Closure;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
        $supported = self::supported();

        if ($preferred !== null && in_array($preferred, $supported, true)) {
            return $preferred;
        }

        $userLocale = self::userStoredLocale();
        if ($userLocale !== null) {
            return $userLocale;
        }

        $sessionLocale = session(self::SESSION_KEY);
        if (is_string($sessionLocale) && in_array($sessionLocale, $supported, true)) {
            return $sessionLocale;
        }

        $accept = request()->getPreferredLanguage($supported);
        if (is_string($accept) && in_array($accept, $supported, true)) {
            return $accept;
        }

        $default = config('app.locale', 'zh_CN');

        return in_array($default, $supported, true) ? $default : ($supported[0] ?? 'zh_CN');
    }

    public static function userStoredLocale(): ?string
    {
        if (! class_exists(Admin::class)) {
            return null;
        }

        $user = Admin::user();
        if (! $user) {
            return null;
        }

        $table = (string) config('admin.database.users_table', 'admin_users');
        if (! Schema::hasColumn($table, 'locale')) {
            return null;
        }

        $locale = $user->getAttribute('locale');
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        return in_array($locale, self::supported(), true) ? $locale : null;
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
