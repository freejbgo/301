<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function switch(Request $request)
    {
        $locale = (string) $request->input('locale', '');
        if (! in_array($locale, SetAdminLocale::supported(), true)) {
            if ($request->expectsJson()) {
                return response()->json(['status' => false, 'message' => 'invalid locale'], 422);
            }

            return redirect()->back();
        }

        session([SetAdminLocale::SESSION_KEY => $locale]);
        app()->setLocale($locale);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => true]);
        }

        return redirect()->back();
    }
}
