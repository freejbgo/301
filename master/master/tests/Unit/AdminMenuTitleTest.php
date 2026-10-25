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
}
