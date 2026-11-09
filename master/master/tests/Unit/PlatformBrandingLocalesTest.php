<?php

namespace Tests\Unit;

use App\Support\PlatformBranding;
use Tests\TestCase;

class PlatformBrandingLocalesTest extends TestCase
{
    public function test_locales_block_resolves_per_locale(): void
    {
        $stored = [
            'locales' => [
                'en' => [
                    'system_name' => 'English Name',
                    'page_title' => 'English Page',
                    'login_title' => 'English Login',
                ],
                'zh_CN' => [
                    'system_name' => '简体名',
                    'page_title' => '',
                    'login_title' => '',
                ],
            ],
            'logo' => 'branding/logo.png',
        ];

        $en = PlatformBranding::resolveForLocale($stored, 'en');
        $this->assertSame('English Name', $en['system_name']);
        $this->assertSame('English Page', $en['page_title']);
        $this->assertSame('English Login', $en['login_title']);
        $this->assertSame('branding/logo.png', $en['logo']);
    }

    public function test_empty_page_and_login_title_follow_system_name(): void
    {
        $stored = [
            'locales' => [
                'zh_TW' => [
                    'system_name' => '繁體標題',
                    'page_title' => '',
                    'login_title' => '',
                ],
            ],
        ];

        $tw = PlatformBranding::resolveForLocale($stored, 'zh_TW');
        $this->assertSame('繁體標題', $tw['page_title']);
        $this->assertSame('繁體標題', $tw['login_title']);
    }

    public function test_missing_locale_falls_back_to_zh_cn(): void
    {
        $stored = [
            'locales' => [
                'zh_CN' => [
                    'system_name' => '简体名',
                    'page_title' => '',
                    'login_title' => '',
                ],
            ],
        ];

        $en = PlatformBranding::resolveForLocale($stored, 'en');
        $this->assertSame('简体名', $en['system_name']);
    }

    public function test_flat_json_without_locales_uses_language_defaults(): void
    {
        $stored = [
            'system_name' => 'Ignored legacy flat',
            'page_title' => 'Legacy Page',
            'login_title' => 'Legacy Login',
        ];

        $cn = PlatformBranding::resolveForLocale($stored, 'zh_CN');
        $this->assertNotSame('Ignored legacy flat', $cn['system_name']);
        $this->assertSame(__('redi-branding.default_system_name', [], 'zh_CN'), $cn['system_name']);
    }
}
