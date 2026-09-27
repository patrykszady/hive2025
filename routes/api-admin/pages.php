<?php

use App\Http\Controllers\Api\Admin\V1\PageController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// ss-systems' Pages screen (App\Livewire\Admin\PageList) — the marketing
// site's Blade views, listed the way App\Support\MarketingPages enumerates
// them. See App\Http\Controllers\Api\Admin\V1\PageController's docblock for
// why store()/destroy() always refuse.
AdminApi::declare('pages');

Route::get('pages', [PageController::class, 'index'])->name('pages.index');
Route::get('pages/types', [PageController::class, 'types'])->name('pages.types');
Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');
Route::put('pages/{page}', [PageController::class, 'update'])->name('pages.update');
Route::post('pages', [PageController::class, 'store'])->name('pages.store');
Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
