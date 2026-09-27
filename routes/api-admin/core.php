<?php

use App\Http\Controllers\Api\Admin\V1\DashboardStatsController;
use App\Http\Controllers\Api\Admin\V1\PingController;
use App\Http\Controllers\Api\Admin\V1\PlatformsController;
use App\Http\Controllers\Api\Admin\V1\SeoSnapshotController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// What every connected site answers: who it is, its dashboard numbers, and
// the SEO snapshot the central admin's SEO screen draws.
AdminApi::declare('dashboard-stats', 'seo');

    Route::get('ping', PingController::class)->name('ping');
    Route::get('dashboard-stats', DashboardStatsController::class)->name('dashboard-stats');
    Route::get('seo/snapshot', SeoSnapshotController::class)->name('seo.snapshot');
    Route::get('platforms/status', [PlatformsController::class, 'status'])->name('platforms.status');
