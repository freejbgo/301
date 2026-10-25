<?php

use App\Admin\Controllers\LocaleController;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

$adminPrefix = trim((string) config('admin.route.prefix', 'admin'), '/');

Route::middleware(['web', SetAdminLocale::class])
    ->prefix($adminPrefix !== '' ? $adminPrefix : 'admin')
    ->group(function () {
        Route::match(['get', 'post'], 'locale', [LocaleController::class, 'switch']);
    });

Route::get('/', function () {
    if (config('app.env') === 'production') {
        return redirect('/admin');
    }

    return view('welcome');
});
