<?php

namespace Tests\Unit;

use App\Http\Middleware\SetAdminLocale;
use App\Models\Administrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SetAdminLocaleSyncTest extends TestCase
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
        config(['admin.database.users_table' => 'admin_users']);

        Schema::create('admin_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 120)->unique();
            $table->string('password', 80);
            $table->string('name');
            $table->string('avatar')->nullable();
            $table->string('locale', 12)->nullable();
            $table->timestamps();
        });
    }

    public function test_sync_applies_stored_user_locale_when_nothing_was_chosen(): void
    {
        $user = Administrator::create([
            'username' => 'ops',
            'password' => 'secret',
            'name' => 'Ops',
            'locale' => 'en',
        ]);

        session()->forget(SetAdminLocale::SESSION_KEY);

        SetAdminLocale::syncForAuthenticatedUser($user);

        $this->assertSame('en', session(SetAdminLocale::SESSION_KEY));
        $this->assertSame('en', app()->getLocale());
    }

    public function test_login_page_choice_overrides_stored_locale(): void
    {
        $user = Administrator::create([
            'username' => 'ops',
            'password' => 'secret',
            'name' => 'Ops',
            'locale' => 'zh_CN',
        ]);

        session([SetAdminLocale::SESSION_KEY => 'en']);

        SetAdminLocale::syncForAuthenticatedUser($user);

        $this->assertSame('en', $user->fresh()->locale);
        $this->assertSame('en', session(SetAdminLocale::SESSION_KEY));
        $this->assertSame('en', app()->getLocale());
    }

    public function test_sync_persists_session_locale_when_user_has_none(): void
    {
        $user = Administrator::create([
            'username' => 'tenant',
            'password' => 'secret',
            'name' => 'Tenant',
            'locale' => null,
        ]);

        session([SetAdminLocale::SESSION_KEY => 'zh_TW']);

        SetAdminLocale::syncForAuthenticatedUser($user);

        $this->assertSame('zh_TW', $user->fresh()->locale);
        $this->assertSame('zh_TW', app()->getLocale());
    }

    public function test_browser_language_maps_chinese_and_defaults_other_languages_to_english(): void
    {
        $this->assertSame('zh_CN', SetAdminLocale::fromRequestLanguages(['zh_CN', 'zh', 'en']));
        $this->assertSame('zh_CN', SetAdminLocale::fromRequestLanguages(['zh-Hans', 'en']));
        $this->assertSame('zh_TW', SetAdminLocale::fromRequestLanguages(['zh_TW', 'zh', 'en']));
        $this->assertSame('zh_TW', SetAdminLocale::fromRequestLanguages(['zh-HK', 'zh', 'en-US']));
        $this->assertSame('zh_TW', SetAdminLocale::fromRequestLanguages(['zh-Hant']));
        $this->assertSame('en', SetAdminLocale::fromRequestLanguages(['en_US', 'en']));
        $this->assertSame('en', SetAdminLocale::fromRequestLanguages(['ja', 'ko']));
        $this->assertSame('en', SetAdminLocale::fromRequestLanguages([]));
    }
}
