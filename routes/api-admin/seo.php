<?php

use App\Http\Controllers\Api\Admin\V1\GscErrorController;
use App\Http\Controllers\Api\Admin\V1\SeoReportController;
use App\Http\Controllers\Api\Admin\V1\SeoSnapshotController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// The SEO screen: the live snapshot (Site Pulse, health, search
// performance, GSC coverage, sitemaps), the shared report library, and the
// dedicated GSC Errors screen. See SeoSnapshotController's docblock for
// the full shape.
AdminApi::declare('seo');

Route::get('seo/snapshot', SeoSnapshotController::class)->name('seo.snapshot');
Route::post('seo/snapshot/refresh', [SeoSnapshotController::class, 'refreshSnapshot'])->name('seo.snapshot.refresh');
Route::get('seo/top-rows', [SeoSnapshotController::class, 'topRows'])->name('seo.top-rows');

Route::get('seo/reports', [SeoReportController::class, 'index'])->name('seo.reports.index');
Route::get('seo/reports/{report}', [SeoReportController::class, 'show'])->name('seo.reports.show');
Route::post('seo/reports/{report}/regenerate', [SeoReportController::class, 'regenerate'])->name('seo.reports.regenerate');

Route::get('seo/gsc-errors', [GscErrorController::class, 'index'])->name('seo.gsc-errors.index');
Route::post('seo/gsc-errors/refresh', [GscErrorController::class, 'refresh'])->name('seo.gsc-errors.refresh');
Route::post('seo/gsc-errors/prune-retired', [GscErrorController::class, 'pruneRetired'])->name('seo.gsc-errors.prune-retired');
Route::get('seo/gsc-errors/export', [GscErrorController::class, 'export'])->name('seo.gsc-errors.export');
