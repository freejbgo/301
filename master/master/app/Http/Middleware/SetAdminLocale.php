<?php

namespace App\Http\Middleware;

use Closure;
use Dcat\Admin\Admin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;

class SetAdminLocale
{
    public const SESSION_KEY = 'admin_locale';

    public const COOKIE_KEY = 'admin_locale';

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

        $sessionLocale = self::validLocale(session(self::SESSION_KEY));
        if ($sessionLocale !== null) {
            return $sessionLocale;
        }

        $userLocale = self::userStoredLocale();
        if ($userLocale !== null) {
            return $userLocale;
        }

        $cookieLocale = self::validLocale(request()->cookie(self::COOKIE_KEY));
        if ($cookieLocale !== null) {
            return $cookieLocale;
        }

        return self::fromRequestLanguages(request()->getLanguages());
    }

    /**
     * Map an Accept-Language list. Simplified and Traditional Chinese stay themselves;
     * every other language is English.
     *
     * @param  list<string>  $languages
     */
    public static function fromRequestLanguages(array $languages): string
    {
        foreach ($languages as $language) {
            $mapped = self::mapLanguageTag((string) $language);
            if ($mapped !== null && in_array($mapped, self::supported(), true)) {
                return $mapped;
            }
        }

        return in_array('en', self::supported(), true) ? 'en' : (self::supported()[0] ?? 'en');
    }

    public static function mapLanguageTag(string $tag): ?string
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        if ($tag === '') {
            return null;
        }

        if ($tag === 'zh' || $tag === 'zh-cn' || $tag === 'zh-sg' || str_starts_with($tag, 'zh-hans')) {
            return 'zh_CN';
        }

        if (in_array($tag, ['zh-tw', 'zh-hk', 'zh-mo'], true) || str_starts_with($tag, 'zh-hant')) {
            return 'zh_TW';
        }

        if (str_starts_with($tag, 'zh-')) {
            return preg_match('/(?:^|-)(?:hant|tw|hk|mo)(?:-|$)/', $tag) === 1 ? 'zh_TW' : 'zh_CN';
        }

        if ($tag === 'en' || str_starts_with($tag, 'en-')) {
            return 'en';
        }

        return null;
    }

    public static function validLocale(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, self::supported(), true) ? $locale : null;
    }

    public static function rememberExplicitLocale(string $locale): void
    {
        session([self::SESSION_KEY => $locale]);
        Cookie::queue(cookie(
            self::COOKIE_KEY,
            $locale,
            60 * 24 * 365,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax'
        ));
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

    /**
     * After admin login. A language picked on the login page wins over the account's saved locale.
     */
    public static function syncForAuthenticatedUser(?Authenticatable $user): void
    {
        $chosen = self::validLocale(session(self::SESSION_KEY));
        if ($chosen === null) {
            $chosen = self::validLocale(request()->cookie(self::COOKIE_KEY));
        }

        if ($chosen !== null) {
            self::persistUserLocale($user, $chosen);
            self::rememberExplicitLocale($chosen);
            app()->setLocale($chosen);

            return;
        }

        $stored = self::localeOn($user);
        if ($stored !== null) {
            self::rememberExplicitLocale($stored);
            app()->setLocale($stored);

            return;
        }

        app()->setLocale(self::resolve());
    }

    protected static function persistUserLocale(?Authenticatable $user, string $locale): void
    {
        if ($user === null || ! method_exists($user, 'forceFill') || ! method_exists($user, 'save')) {
            return;
        }

        $table = (string) config('admin.database.users_table', 'admin_users');
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'locale')) {
            return;
        }

        if ($user->getAttribute('locale') === $locale) {
            return;
        }

        $user->forceFill(['locale' => $locale])->save();
    }

    protected static function localeOn(?Authenticatable $user): ?string
    {
        if ($user === null || ! method_exists($user, 'getAttribute')) {
            return null;
        }

        $table = (string) config('admin.database.users_table', 'admin_users');
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'locale')) {
            return null;
        }

        return self::validLocale($user->getAttribute('locale'));
    }
}
