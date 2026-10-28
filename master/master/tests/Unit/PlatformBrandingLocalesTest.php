<?php

namespace Tests\Unit;

use App\Support\PlatformBranding;
use Tests\TestCase;

class PlatformBrandingLocalesTest extends TestCase
{
    public function test_legacy_flat_json_resolves_for_each_locale(): void
    {
        $stored = [
            'system_name' => 'Legacy CN',
            'page_title' => 'Legacy Page',
            'login_title' => 'Legacy Login',
            'logo' => 'branding/logo.png',
        ];

        $en = PlatformBranding::resolveForLocale($stored, 'en');
        $this->assertSame('Legacy CN', $en['system_name']);
        $this->assertSame('Legacy Page', $en['page_title']);
        $this->assertSame('Legacy Login', $en['login_title']);
        $this->assertSame('branding/logo.png', $en['logo']);
    }

    public function test_locales_block_overrides_legacy_for_matching_locale(): void
    {
        $stored = [
            'system_name' => 'Legacy',
            'locales' => [
                'en' => [
                    'system_name' => 'English Name',
                    'page_title' => '',
                    'login_title' => '',
                ],
            ],
        ];

        $en = PlatformBranding::resolveForLocale($stored, 'en');
        $this->assertSame('English Name', $en['system_name']);
        $this->assertSame('English Name', $en['page_title']);
        $this->assertSame('English Name', $en['login_title']);
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

    public function test_missing_locale_falls_back_to_zh_cn_then_legacy(): void
    {
        $stored = [
            'system_name' => 'Top Level',
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
}
