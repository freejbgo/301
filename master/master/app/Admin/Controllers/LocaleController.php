<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetAdminLocale;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class LocaleController extends Controller
{
    public function switch(Request $request)
    {
        $locale = (string) $request->input('locale', '');
        if (! in_array($locale, SetAdminLocale::supported(), true)) {
            if ($request->expectsJson()) {
                return response()->json(['status' => false, 'message' => __('redi-locale.invalid')], 422);
            }

            return redirect()->back();
        }

        session([SetAdminLocale::SESSION_KEY => $locale]);
        app()->setLocale($locale);

        $user = Admin::user();
        $table = (string) config('admin.database.users_table', 'admin_users');
        if ($user && Schema::hasColumn($table, 'locale')) {
            $user->forceFill(['locale' => $locale])->save();
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => true]);
        }

        return redirect()->back();
    }
}
