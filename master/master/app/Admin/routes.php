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

    $router->post('ops/requeue-failed-deployments', 'OpsController@requeueFailedDeployments');

    $router->resource('group', 'RediAdminGroupController');
    $router->get('domain/export', 'RediAdminDomainController@exportCsv');
    $router->resource('domain', 'RediAdminDomainController');
    $router->get('platform-settings', 'PlatformSettingsController@index');
    $router->get('platform-dns', 'PlatformDnsController@index');
    $router->post('platform-dns/sync', 'PlatformDnsController@sync');
    $router->resource('nodes', 'RediNodeController');
});
