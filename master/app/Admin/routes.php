<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Dcat\Admin\Admin;

Admin::routes();

//Route::get(uri: '/', function(){
//    return redirect(to: 'admin');
//});

Route::group([
    'prefix'     => config('admin.route.prefix'),
    'namespace'  => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
], function (Router $router) {

    $router->get('/', 'HomeController@index');

    $router->resource('group', 'RediAdminGroupController');
    $router->resource('domain', 'RediAdminDomainController');
    $router->get('platform-settings', 'PlatformSettingsController@index');
});
