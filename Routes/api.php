<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

$route_prefix = config('importexport.route_prefix', 'manager');
$route_url_prefix = $route_prefix ? $route_prefix . '/' : '';
$route_name_prefix = $route_prefix ? $route_prefix . '.' : '';

Route::prefix("{$route_url_prefix}import-export")->name("api.{$route_name_prefix}import-export.")->group(function () {
	Route::get('/record', 'ImportexportController@items')->name('items');
	Route::get('/record/approve', 'ImportexportController@approveItem')->name('item.approve');
	Route::post('/download', 'ImportexportController@download')->name('download');
	Route::post('/status', "ImportexportController@progress")->name('progress');
});
