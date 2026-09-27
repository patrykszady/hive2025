<?php

use App\Http\Controllers\Api\Admin\V1\LandingPageController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// Hand-created ad-campaign pages for Hive's own marketing site
// (`/lp/{slug}`) — same index/services/store/publish/unpublish/destroy
// contract as gs.construction's and dawnsellshomes.com's landing-pages
// domain, so ss-systems' shared Livewire\Admin\LandingPages screen renders
// this site's rows unchanged (see App\Models\LandingPage's docblock: no
// Projects-proof domain here either, so the screen drops its Proof column
// for this site, same as it does for dawnsellshomes.com).
AdminApi::declare('landing-pages');

Route::get('landing-pages', [LandingPageController::class, 'index'])->name('landing-pages.index');
Route::get('landing-pages/services', [LandingPageController::class, 'services'])->name('landing-pages.services');
Route::post('landing-pages', [LandingPageController::class, 'store'])->name('landing-pages.store');
Route::patch('landing-pages/{landingPage}/publish', [LandingPageController::class, 'publish'])->name('landing-pages.publish');
Route::patch('landing-pages/{landingPage}/unpublish', [LandingPageController::class, 'unpublish'])->name('landing-pages.unpublish');
Route::delete('landing-pages/{landingPage}', [LandingPageController::class, 'destroy'])->name('landing-pages.destroy');
