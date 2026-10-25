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
            return response()->json(['status' => false, 'message' => 'invalid locale'], 422);
        }

        session([SetAdminLocale::SESSION_KEY => $locale]);
        app()->setLocale($locale);

        return response()->json(['status' => true]);
    }
}
