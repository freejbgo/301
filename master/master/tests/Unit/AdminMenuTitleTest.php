<?php

namespace Tests\Unit;

use App\Support\AdminMenuTitle;
use Tests\TestCase;

class AdminMenuTitleTest extends TestCase
{
    public function test_resolves_builtin_index_title(): void
    {
        app()->setLocale('en');

        $this->assertSame('Home', AdminMenuTitle::resolve('Index', '/domain'));
        $this->assertSame('Home', AdminMenuTitle::resolve('主页', '/'));
        $this->assertSame('Home', __('admin.home'));
    }

    public function test_falls_back_to_uri_when_db_title_unknown(): void
    {
        app()->setLocale('en');

        $this->assertSame('Groups', AdminMenuTitle::resolve('自定义分组', 'group'));
    }

    public function test_uri_slug_normalizes_leading_slash(): void
    {
        $this->assertSame('domain', AdminMenuTitle::uriLangSlug('/domain'));
        $this->assertSame('auth.users', AdminMenuTitle::uriLangSlug('auth/users'));
    }

    public function test_resolves_auth_menu_by_uri_when_title_unknown(): void
    {
        app()->setLocale('en');

        $this->assertSame('Permissions', AdminMenuTitle::resolve('Perm', 'auth/permissions'));
    }

    public function test_settings_menu_label_does_not_use_old_name(): void
    {
        app()->setLocale('zh_CN');
        $this->assertSame('301设置', AdminMenuTitle::resolve('lagran', 'lagran'));
        $this->assertSame('301设置', AdminMenuTitle::resolve('301-settings', '301-settings'));

        app()->setLocale('zh_TW');
        $this->assertSame('301設定', AdminMenuTitle::resolve('301-settings', '301-settings'));

        app()->setLocale('en');
        $this->assertSame('301 settings', AdminMenuTitle::resolve('301-settings', '301-settings'));

        foreach (['zh_CN', 'zh_TW', 'en'] as $locale) {
            $copy = include resource_path("lang/{$locale}/redi-lagran.php");
            $menu = include resource_path("lang/{$locale}/menu.php");
            $shown = json_encode([
                array_values(self::leafValues($copy)),
                $menu['titles']['lagran'],
                $menu['titles']['301-settings'],
                $menu['uris']['lagran'],
                $menu['uris']['301-settings'],
            ], JSON_UNESCAPED_UNICODE);
            $this->assertDoesNotMatchRegularExpression('/lagran/i', $shown);
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private static function leafValues($value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $out = array_merge($out, self::leafValues($item));
                continue;
            }
            $out[] = (string) $item;
        }

        return $out;
    }
}
