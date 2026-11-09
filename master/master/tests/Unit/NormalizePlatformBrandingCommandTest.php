<?php

namespace Tests\Unit;

use App\Support\PlatformBranding;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NormalizePlatformBrandingCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        Schema::create('admin_settings', function (Blueprint $table) {
            $table->string('slug', 100)->primary();
            $table->longText('value');
            $table->timestamps();
        });
    }

    public function test_migrates_flat_titles_into_locales_zh_cn(): void
    {
        $flat = [
            'system_name' => '旧系统名',
            'page_title' => '旧页面',
            'login_title' => '旧登录',
        ];
        \DB::table('admin_settings')->insert([
            'slug' => PlatformBranding::SETTINGS_SLUG,
            'value' => json_encode($flat, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('redi:normalize-platform-branding');
        $this->assertSame(0, $exit);

        $row = \DB::table('admin_settings')->where('slug', PlatformBranding::SETTINGS_SLUG)->first();
        $decoded = json_decode($row->value, true);
        $this->assertSame('旧系统名', $decoded['locales']['zh_CN']['system_name']);
        $this->assertSame('旧页面', $decoded['locales']['zh_CN']['page_title']);
        $this->assertSame('旧登录', $decoded['locales']['zh_CN']['login_title']);
    }

    public function test_dry_run_does_not_write(): void
    {
        $flat = ['system_name' => 'Only Name'];
        $encoded = json_encode($flat, JSON_UNESCAPED_UNICODE);
        \DB::table('admin_settings')->insert([
            'slug' => PlatformBranding::SETTINGS_SLUG,
            'value' => $encoded,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('redi:normalize-platform-branding', ['--dry-run' => true]);
        $row = \DB::table('admin_settings')->where('slug', PlatformBranding::SETTINGS_SLUG)->first();
        $this->assertSame($encoded, $row->value);
    }

    public function test_skips_when_locales_already_populated(): void
    {
        $stored = [
            'system_name' => 'flat ignored',
            'locales' => [
                'zh_CN' => [
                    'system_name' => '已有简体',
                    'page_title' => '',
                    'login_title' => '',
                ],
            ],
        ];
        $encoded = json_encode($stored, JSON_UNESCAPED_UNICODE);
        \DB::table('admin_settings')->insert([
            'slug' => PlatformBranding::SETTINGS_SLUG,
            'value' => $encoded,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('redi:normalize-platform-branding');
        $row = \DB::table('admin_settings')->where('slug', PlatformBranding::SETTINGS_SLUG)->first();
        $this->assertSame($encoded, $row->value);
    }
}
