<?php

use App\Http\Controllers\Api\App\V1\DiagnosticsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Diagnostics: the app's on-device calling log
|--------------------------------------------------------------------------
|
| Calls placed while the app is suspended, backgrounded, or driven from
| Recents, Siri, or the locked screen run where Metro's log channel is
| dead, so failures there are otherwise invisible. The app keeps a small
| log of its own and uploads it here the next time it can reach the
| server — see App\Services\Calls\AppCallingDiagnostics.
*/
Route::post('diagnostics/calling', [DiagnosticsController::class, 'calling'])
    ->middleware('throttle:60,1')
    ->name('diagnostics.calling');

// JavaScript errors the app caught on the phone — App\Services\Diagnostics\AppErrorReports.
Route::post('diagnostics/errors', [DiagnosticsController::class, 'errors'])
    ->middleware('throttle:30,1')
    ->name('diagnostics.errors');
