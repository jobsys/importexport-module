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

use Modules\Importexport\Http\Controllers\ImportexportController;

Route::prefix("manager/import-export")->name("api.manager.import-export.")->group(function () {
	Route::get('/record', [ImportexportController::class, 'items'])->name('items');
	Route::get('/record/approve', [ImportexportController::class, 'approveItem'])->name('item.approve');
	Route::post('/download', [ImportexportController::class, 'download'])->name('download');
	Route::post('/download-error-file', [ImportexportController::class, 'downloadErrorFile'])->name('download-error-file');
	Route::post('/import-progress', [ImportexportController::class, 'importProgress'])->name('import.progress');
	Route::post('/export-progress', [ImportexportController::class, 'exportProgress'])->name('export.progress');
});
