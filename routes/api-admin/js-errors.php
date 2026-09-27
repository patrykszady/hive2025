<?php

use App\Http\Controllers\Api\Admin\V1\JsErrorController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// JS Errors board: SsSystems\Platform\Pulse's `jserr` site_events rows,
// grouped live by App\Support\JsErrorGroups — the ss.systems platform
// dashboard's JS errors board reads this across every connected site.
// summary/resolve-all before {jsError} so neither is ever read as a group id.
AdminApi::declare('js-errors');

Route::get('js-errors', [JsErrorController::class, 'index'])->name('js-errors.index');
Route::get('js-errors/summary', [JsErrorController::class, 'summary'])->name('js-errors.summary');
Route::patch('js-errors/resolve-all', [JsErrorController::class, 'resolveAll'])->name('js-errors.resolve-all');
Route::patch('js-errors/{jsError}/resolve', [JsErrorController::class, 'resolve'])->name('js-errors.resolve');
Route::patch('js-errors/{jsError}/unresolve', [JsErrorController::class, 'unresolve'])->name('js-errors.unresolve');
Route::delete('js-errors/{jsError}', [JsErrorController::class, 'destroy'])->name('js-errors.destroy');
