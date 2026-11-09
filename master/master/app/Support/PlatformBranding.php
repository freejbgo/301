<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlatformBranding
{
    public const SETTINGS_SLUG = 'platform_branding';

    public const CACHE_KEY = 'platform_branding_v2';

    /** @return list<string> */
    public static function brandingLocales(): array
    {
        return config('redi.admin_locales', ['zh_CN', 'zh_TW', 'en']);
    }

    public static function defaults(): array
    {
        return [
            'system_name' => __('redi-branding.default_system_name'),
            'page_title' => __('redi-branding.default_page_title'),
            'login_title' => __('redi-branding.default_login_title'),
            'logo' => '',
            'logo_mini' => '',
            'favicon' => '',
        ];
    }

    /**
     * Resolved branding for the current (or given) UI locale — used when rendering admin.
     *
     * @return array{system_name: string, page_title: string, login_title: string, logo: string, logo_mini: string, favicon: string}
     */
    public static function get(?string $locale = null): array
    {
        return self::resolveForLocale(self::getStored(), $locale ?? app()->getLocale());
    }

    /**
     * Values for the system-appearance form (all locale tabs + shared images).
     *
     * @return array<string, mixed>
     */
    public static function formDefaults(): array
    {
        $stored = self::getStored();
        $locales = is_array($stored['locales'] ?? null) ? $stored['locales'] : [];

        $out = [
            'logo' => (string) ($stored['logo'] ?? ''),
            'logo_mini' => (string) ($stored['logo_mini'] ?? ''),
            'favicon' => (string) ($stored['favicon'] ?? ''),
            'locales' => [],
        ];

        $hasStoredText = false;
        foreach ($locales as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (['system_name', 'page_title', 'login_title'] as $field) {
                if (trim((string) ($block[$field] ?? '')) !== '') {
                    $hasStoredText = true;
                    break 2;
                }
            }
        }

        foreach (self::brandingLocales() as $code) {
            $block = is_array($locales[$code] ?? null) ? $locales[$code] : [];
            $name = trim((string) ($block['system_name'] ?? ''));
            $page = trim((string) ($block['page_title'] ?? ''));
            $login = trim((string) ($block['login_title'] ?? ''));

            if (! $hasStoredText) {
                $name = self::defaultFieldForLocale($code, 'system_name');
                $page = self::defaultFieldForLocale($code, 'page_title');
                $login = self::defaultFieldForLocale($code, 'login_title');
            }

            $out['locales'][$code] = [
                'system_name' => $name,
                'page_title' => $page,
                'login_title' => $login,
            ];
        }

        return $out;
    }

    /**
     * 将旧版仅存顶层的 system_name/page_title/login_title 迁入 locales.zh_CN。
     *
     * @return array{status: string, migrated: bool}
     */
    public static function migrateFlatStoredToLocales(bool $dryRun = false): array
    {
        $row = DB::table('admin_settings')->where('slug', self::SETTINGS_SLUG)->first();
        if (! $row || $row->value === '') {
            return ['status' => 'empty', 'migrated' => false];
        }

        $decoded = json_decode($row->value, true);
        if (! is_array($decoded)) {
            return ['status' => 'invalid_json', 'migrated' => false];
        }

        $locales = is_array($decoded['locales'] ?? null) ? $decoded['locales'] : [];
        foreach ($locales as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (['system_name', 'page_title', 'login_title'] as $field) {
                if (trim((string) ($block[$field] ?? '')) !== '') {
                    return ['status' => 'already_locales', 'migrated' => false];
                }
            }
        }

        $name = trim((string) ($decoded['system_name'] ?? ''));
        if ($name === '') {
            return ['status' => 'nothing_to_migrate', 'migrated' => false];
        }

        $page = trim((string) ($decoded['page_title'] ?? ''));
        $login = trim((string) ($decoded['login_title'] ?? ''));
        if ($page === '') {
            $page = $name;
        }
        if ($login === '') {
            $login = $name;
        }

        $decoded['locales'] = $locales;
        $decoded['locales']['zh_CN'] = [
            'system_name' => $name,
            'page_title' => $page,
            'login_title' => $login,
        ];
        $decoded['system_name'] = $name;
        $decoded['page_title'] = $page;
        $decoded['login_title'] = $login;

        if ($dryRun) {
            return ['status' => 'dry_run', 'migrated' => true];
        }

        $now = now();
        DB::table('admin_settings')->where('slug', self::SETTINGS_SLUG)->update([
            'value' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);
        Cache::forget(self::CACHE_KEY);
        Cache::forget('platform_branding_v1');

        return ['status' => 'migrated', 'migrated' => true];
    }

    public static function save(array $input): void
    {
        $locales = [];
        $rawLocales = is_array($input['locales'] ?? null) ? $input['locales'] : [];

        foreach (self::brandingLocales() as $code) {
            $block = is_array($rawLocales[$code] ?? null) ? $rawLocales[$code] : [];
            $locales[$code] = [
                'system_name' => trim((string) ($block['system_name'] ?? '')),
                'page_title' => trim((string) ($block['page_title'] ?? '')),
                'login_title' => trim((string) ($block['login_title'] ?? '')),
            ];
        }

        $primaryName = $locales['zh_CN']['system_name'];
        if ($primaryName === '') {
            foreach ($locales as $block) {
                if ($block['system_name'] !== '') {
                    $primaryName = $block['system_name'];
                    break;
                }
            }
        }

        $data = [
            'system_name' => $primaryName,
            'page_title' => $locales['zh_CN']['page_title'],
            'login_title' => $locales['zh_CN']['login_title'],
            'logo' => (string) ($input['logo'] ?? ''),
            'logo_mini' => (string) ($input['logo_mini'] ?? ''),
            'favicon' => (string) ($input['favicon'] ?? ''),
            'locales' => $locales,
        ];

        if ($data['page_title'] === '' && $data['system_name'] !== '') {
            $data['page_title'] = $data['system_name'];
        }
        if ($data['login_title'] === '' && $data['system_name'] !== '') {
            $data['login_title'] = $data['system_name'];
        }

        foreach (['logo', 'logo_mini', 'favicon'] as $key) {
            $data[$key] = self::normalizeStoragePath($data[$key]);
        }

        $now = now();
        DB::table('admin_settings')->updateOrInsert(
            ['slug' => self::SETTINGS_SLUG],
            [
                'value' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        Cache::forget(self::CACHE_KEY);
        Cache::forget('platform_branding_v1');
    }

    public static function applyAdminConfig(): void
    {
        $data = self::get();
        $systemName = $data['system_name'];
        $pageTitle = $data['page_title'];

        config([
            'admin.name' => $systemName,
            'admin.title' => $pageTitle,
            'admin.logo' => self::buildLogoHtml($data['logo'], $systemName, false),
            'admin.logo-mini' => self::buildLogoHtml(
                $data['logo_mini'] ?: $data['logo'],
                $systemName,
                true
            ),
            'admin.favicon' => self::publicUrl($data['favicon']),
        ]);
    }

    public static function applyLoginPageName(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $prefix = trim((string) config('admin.route.prefix'), '/');
        if (! request()->is($prefix.'/auth/login')) {
            return;
        }

        $loginTitle = self::get()['login_title'] ?? '';
        if ($loginTitle !== '') {
            config(['admin.name' => $loginTitle]);
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{system_name: string, page_title: string, login_title: string, logo: string, logo_mini: string, favicon: string}
     */
    public static function resolveForLocale(array $stored, string $locale): array
    {
        if (! in_array($locale, self::brandingLocales(), true)) {
            $locale = self::brandingLocales()[0] ?? 'zh_CN';
        }

        $systemName = self::resolveField($stored, $locale, 'system_name');
        $pageTitle = self::resolveField($stored, $locale, 'page_title');
        $loginTitle = self::resolveField($stored, $locale, 'login_title');

        if ($pageTitle === '') {
            $pageTitle = $systemName;
        }
        if ($loginTitle === '') {
            $loginTitle = $systemName;
        }

        return [
            'system_name' => $systemName,
            'page_title' => $pageTitle,
            'login_title' => $loginTitle,
            'logo' => (string) ($stored['logo'] ?? ''),
            'logo_mini' => (string) ($stored['logo_mini'] ?? ''),
            'favicon' => (string) ($stored['favicon'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    protected static function resolveField(array $stored, string $locale, string $field): string
    {
        $locales = is_array($stored['locales'] ?? null) ? $stored['locales'] : [];

        if (is_array($locales[$locale] ?? null)) {
            $block = $locales[$locale];
            if (array_key_exists($field, $block)) {
                $fromBlock = trim((string) $block[$field]);
                if ($fromBlock !== '') {
                    return $fromBlock;
                }

                return '';
            }
        }
        if ($locale !== 'zh_CN' && is_array($locales['zh_CN'] ?? null)) {
            $fromCn = trim((string) ($locales['zh_CN'][$field] ?? ''));
            if ($fromCn !== '') {
                return $fromCn;
            }
        }

        return self::defaultFieldForLocale($locale, $field);
    }

    protected static function defaultFieldForLocale(string $locale, string $field): string
    {
        $key = match ($field) {
            'system_name' => 'redi-branding.default_system_name',
            'page_title' => 'redi-branding.default_page_title',
            'login_title' => 'redi-branding.default_login_title',
            default => '',
        };
        if ($key === '') {
            return '';
        }

        return trim((string) trans($key, [], $locale));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function getStored(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $row = DB::table('admin_settings')->where('slug', self::SETTINGS_SLUG)->first();
            if (! $row || $row->value === '') {
                return [];
            }

            $decoded = json_decode($row->value, true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    protected static function buildLogoHtml(string $path, string $systemName, bool $mini): string
    {
        $url = self::publicUrl($path) ?: '/vendor/dcat-admin/images/logo.png';
        $width = $mini ? 30 : 35;

        return sprintf(
            '<img src="%s" width="%d" alt="">%s',
            e($url),
            $width,
            $mini ? '' : ' &nbsp;'.e($systemName)
        );
    }

    protected static function publicUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * 上传文件仅存 public 盘相对路径；拒绝路径穿越与外链写入存储字段。
     */
    protected static function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        if (strpos($path, '..') !== false || strpos($path, '/') === 0) {
            return '';
        }

        return ltrim($path, '/');
    }
}
