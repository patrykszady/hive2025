<?php

use App\Http\Controllers\Api\App\V1\BidController as AppBidController;
use App\Http\Controllers\Api\App\V1\ProjectController as AppProjectController;
use App\Http\Controllers\Api\App\V1\ProjectDistributionsController as AppProjectDistributionsController;
use App\Http\Controllers\Api\App\V1\ProjectEmailTrackingController as AppProjectEmailTrackingController;
use App\Http\Controllers\Api\App\V1\ProjectFinancesController as AppProjectFinancesController;
use App\Http\Controllers\Api\App\V1\ProjectMaterialsController as AppProjectMaterialsController;
use App\Http\Controllers\Api\App\V1\ProjectPhotoController as AppProjectPhotoController;
use App\Http\Controllers\Api\App\V1\ProjectVendorsController as AppProjectVendorsController;
use App\Http\Controllers\Api\App\V1\TaskController as AppTaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Jobs: creating projects, the project page sections the app didn't have
| yet, project photos, tasks, and bids
|--------------------------------------------------------------------------
|
| Existing project/planner read routes (projects.index/show/statuses,
| projects.status.update, planner.*, action-items.*) stay inline in
| routes/api.php — this file is additive only. See each controller's
| docblock for the web component/policy it mirrors.
*/

// PROJECTS — creating one. index/show/statuses/status stay in routes/api.php.
Route::post('projects', [AppProjectController::class, 'store'])->name('projects.store');

// PROJECT SECTIONS the app didn't show yet — each Admin-only, same as the
// web project page's own gates (see each controller's docblock).
Route::get('projects/{project}/finances', [AppProjectFinancesController::class, 'show'])->whereNumber('project')->name('projects.finances.show');
Route::get('projects/{project}/vendors', [AppProjectVendorsController::class, 'index'])->whereNumber('project')->name('projects.vendors.index');
Route::post('projects/{project}/vendors', [AppProjectVendorsController::class, 'store'])->whereNumber('project')->name('projects.vendors.store');
Route::get('projects/{project}/materials', [AppProjectMaterialsController::class, 'index'])->whereNumber('project')->name('projects.materials.index');
Route::get('projects/{project}/distributions', [AppProjectDistributionsController::class, 'index'])->whereNumber('project')->name('projects.distributions.index');
Route::get('projects/{project}/email-tracking', [AppProjectEmailTrackingController::class, 'index'])->whereNumber('project')->name('projects.email-tracking.index');

// PROJECT PHOTOS — gallery + upload. Visible to anyone on the project
// (manageImages/view, like the web); see ProjectImageImporter for storage.
Route::get('projects/{project}/photos', [AppProjectPhotoController::class, 'index'])->whereNumber('project')->name('projects.photos.index');
Route::post('projects/{project}/photos', [AppProjectPhotoController::class, 'store'])->whereNumber('project')->name('projects.photos.store');

// BIDS — view and add bids on a project, for a specific vendor (the bid's
// own tenant — see App\Services\Bids\AppBidDirectory/BidWriter).
Route::get('projects/{project}/vendors/{vendor}/bids', [AppBidController::class, 'index'])->whereNumber(['project', 'vendor'])->name('bids.index');
Route::post('projects/{project}/vendors/{vendor}/bids', [AppBidController::class, 'store'])->whereNumber(['project', 'vendor'])->name('bids.store');

// TASKS — create, edit, delete and schedule. The planner's own read routes
// (planner.index, action-items.*) stay in routes/api.php.
Route::post('tasks', [AppTaskController::class, 'store'])->name('tasks.store');
Route::get('tasks/{task}', [AppTaskController::class, 'show'])->whereNumber('task')->name('tasks.show');
Route::post('tasks/{task}', [AppTaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
Route::delete('tasks/{task}', [AppTaskController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');
