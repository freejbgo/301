<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlatformBranding
{
    public const SETTINGS_SLUG = 'platform_branding';

    public const CACHE_KEY = 'platform_branding_v1';

    public static function defaults(): array
    {
        return [
            'system_name' => '301 跳转系统',
            'page_title' => '301 跳转系统',
            'login_title' => '301 跳转系统',
            'logo' => '',
            'logo_mini' => '',
            'favicon' => '',
        ];
    }

    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $row = DB::table('admin_settings')->where('slug', self::SETTINGS_SLUG)->first();
            if (! $row || $row->value === '') {
                return self::defaults();
            }

            $decoded = json_decode($row->value, true);
            if (! is_array($decoded)) {
                return self::defaults();
            }

            return array_merge(self::defaults(), $decoded);
        });
    }

    public static function save(array $input): void
    {
        $data = [
            'system_name' => trim((string) ($input['system_name'] ?? '')),
            'page_title' => trim((string) ($input['page_title'] ?? '')),
            'login_title' => trim((string) ($input['login_title'] ?? '')),
            'logo' => (string) ($input['logo'] ?? ''),
            'logo_mini' => (string) ($input['logo_mini'] ?? ''),
            'favicon' => (string) ($input['favicon'] ?? ''),
        ];

        if ($data['system_name'] === '') {
            $data['system_name'] = self::defaults()['system_name'];
        }
        if ($data['page_title'] === '') {
            $data['page_title'] = $data['system_name'];
        }
        if ($data['login_title'] === '') {
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
