<?php

use App\Http\Controllers\Api\Admin\V1\CitationsController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// The Citations screen: the directory board, the canonical listing
// payload, and the manual status/URL/note edit. See
// CitationsController's docblock for why start/poll/resume/stop/batch
// exist but never produce a real browser session.
AdminApi::declare('citations');

Route::get('citations', [CitationsController::class, 'index'])->name('citations.index');
Route::get('citations/payload', [CitationsController::class, 'payload'])->name('citations.payload');
Route::post('citations/batch', [CitationsController::class, 'batch'])->name('citations.batch');
Route::post('citations/session/poll', [CitationsController::class, 'poll'])->name('citations.session.poll');
Route::post('citations/session/stop', [CitationsController::class, 'stop'])->name('citations.session.stop');
Route::post('citations/{slug}/start', [CitationsController::class, 'start'])->name('citations.start');
Route::post('citations/{slug}/resume', [CitationsController::class, 'resume'])->name('citations.resume');
Route::patch('citations/{slug}', [CitationsController::class, 'update'])->name('citations.update');
Route::get('citations/{slug}/screenshots/{file}', [CitationsController::class, 'screenshot'])->name('citations.screenshot');
