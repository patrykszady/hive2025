<?php

use App\Http\Controllers\Api\Admin\V1\DashboardStatsController;
use App\Http\Controllers\Api\Admin\V1\PingController;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;
use Illuminate\Support\Facades\Route;

// Every connected site answers who it is and its dashboard numbers. `seo`
// and `platforms` are declared in their own files (seo.php, platforms.php)
// — each screen's API is one self-contained file, so two screens never
// edit the same one.
CapabilityRegistry::declare('dashboard-stats');

    Route::get('ping', PingController::class)->name('ping');
    Route::get('dashboard-stats', DashboardStatsController::class)->name('dashboard-stats');
