<?php

use App\Http\Controllers\Api\App\V1\BidController as AppBidController;
use App\Http\Controllers\Api\App\V1\ProjectCollectionController as AppProjectCollectionController;
use App\Http\Controllers\Api\App\V1\ProjectController as AppProjectController;
use App\Http\Controllers\Api\App\V1\ProjectDistributionsController as AppProjectDistributionsController;
use App\Http\Controllers\Api\App\V1\ProjectEmailTrackingController as AppProjectEmailTrackingController;
use App\Http\Controllers\Api\App\V1\ProjectFinancesController as AppProjectFinancesController;
use App\Http\Controllers\Api\App\V1\ProjectMaterialsController as AppProjectMaterialsController;
use App\Http\Controllers\Api\App\V1\ProjectMessageImageController as AppProjectMessageImageController;
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

// PROJECTS — creating, editing and deleting one. index/show/statuses/status
// stay in routes/api.php.
Route::post('projects', [AppProjectController::class, 'store'])->name('projects.store');
Route::post('projects/{project}', [AppProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
Route::delete('projects/{project}', [AppProjectController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');

// PROJECTS INDEX — the status dashboard cards, and the deleted-projects
// recovery table (App\Livewire\Projects\DeletedProjectsTable).
Route::get('projects/status-cards', [AppProjectController::class, 'statusCards'])->name('projects.status-cards');
Route::get('projects/deleted', [AppProjectController::class, 'deleted'])->name('projects.deleted');
Route::post('projects/{project}/restore', [AppProjectController::class, 'restore'])->whereNumber('project')->name('projects.restore');

// PROJECT STATUS HISTORY — edit/delete a past entry, like the web's history
// list (App\Livewire\ProjectStatus\StatusCreate::updateStatus()/deleteStatus()).
Route::post('projects/{project}/statuses/{status}', [AppProjectController::class, 'updateStatusHistory'])->whereNumber(['project', 'status'])->name('projects.statuses.update');
Route::delete('projects/{project}/statuses/{status}', [AppProjectController::class, 'destroyStatusHistory'])->whereNumber(['project', 'status'])->name('projects.statuses.destroy');

// PROJECT SECTIONS the app didn't show yet — each Admin-only, same as the
// web project page's own gates (see each controller's docblock).
Route::get('projects/{project}/finances', [AppProjectFinancesController::class, 'show'])->whereNumber('project')->name('projects.finances.show');
Route::get('projects/{project}/finances/reimbursements-pdf-url', [AppProjectFinancesController::class, 'reimbursementsPdfUrl'])->whereNumber('project')->name('projects.finances.reimbursements-pdf-url');
Route::get('projects/{project}/vendors', [AppProjectVendorsController::class, 'index'])->whereNumber('project')->name('projects.vendors.index');
Route::post('projects/{project}/vendors', [AppProjectVendorsController::class, 'store'])->whereNumber('project')->name('projects.vendors.store');
Route::get('projects/{project}/materials', [AppProjectMaterialsController::class, 'index'])->whereNumber('project')->name('projects.materials.index');
Route::post('projects/{project}/materials', [AppProjectMaterialsController::class, 'store'])->whereNumber('project')->name('projects.materials.store');
Route::get('projects/{project}/distributions', [AppProjectDistributionsController::class, 'index'])->whereNumber('project')->name('projects.distributions.index');
Route::get('projects/{project}/email-tracking', [AppProjectEmailTrackingController::class, 'index'])->whereNumber('project')->name('projects.email-tracking.index');

// PROJECTS INDEX — the "Email Tracking" card beside the project list
// itself (unscoped, or narrowed to one client with ?client_id=), beside
// the project-scoped route above.
Route::get('projects/email-tracking', [AppProjectEmailTrackingController::class, 'forClient'])->name('projects.email-tracking.for-client');

// PROJECT PHOTOS — gallery + upload. Visible to anyone on the project
// (manageImages/view, like the web); see ProjectImageImporter for storage.
Route::get('projects/{project}/photos', [AppProjectPhotoController::class, 'index'])->whereNumber('project')->name('projects.photos.index');
Route::post('projects/{project}/photos', [AppProjectPhotoController::class, 'store'])->whereNumber('project')->name('projects.photos.store');

// PROJECT IMAGES — the Collections hub (App\Livewire\Projects\
// TimelapseStudio's collection cards): list/create/delete a named
// collection, a collection's frames in play order for native playback, and
// curation (reorder/delete a frame, build a timelapse from a selection).
// Every write shares App\Services\Projects\TimelapseCollectionService with
// the web studio, so authorization (view to browse/shoot, manageImages to
// curate) is identical.
Route::get('projects/{project}/collections', [AppProjectCollectionController::class, 'index'])->whereNumber('project')->name('projects.collections.index');
Route::post('projects/{project}/collections', [AppProjectCollectionController::class, 'store'])->whereNumber('project')->name('projects.collections.store');
Route::delete('projects/{project}/collections/{collection}', [AppProjectCollectionController::class, 'destroy'])->whereNumber(['project', 'collection'])->name('projects.collections.destroy');
Route::get('projects/{project}/collections/{collection}/frames', [AppProjectCollectionController::class, 'frames'])->whereNumber(['project', 'collection'])->name('projects.collections.frames');
Route::post('projects/{project}/collections/from-selection', [AppProjectCollectionController::class, 'createFromSelection'])->whereNumber('project')->name('projects.collections.from-selection');
Route::post('projects/{project}/frames/{frame}/move', [AppProjectCollectionController::class, 'moveFrame'])->whereNumber(['project', 'frame'])->name('projects.frames.move');
Route::delete('projects/{project}/frames/{frame}', [AppProjectCollectionController::class, 'destroyFrame'])->whereNumber(['project', 'frame'])->name('projects.frames.destroy');

// PROJECT MESSAGE IMAGES — photos texted about the project, and texting
// project/message photos onward to a conversation (the web studio's
// Message Images card + "Text Selected" modal). Never actually sends in
// tests — fake Queue (SendGroupMms) and Http.
Route::get('projects/{project}/message-images', [AppProjectMessageImageController::class, 'index'])->whereNumber('project')->name('projects.message-images.index');
Route::get('projects/{project}/message-images/threads', [AppProjectMessageImageController::class, 'threads'])->whereNumber('project')->name('projects.message-images.threads');
Route::post('projects/{project}/message-images/text', [AppProjectMessageImageController::class, 'send'])->whereNumber('project')->name('projects.message-images.text');

// BIDS — view and add bids on a project, for a specific vendor (the bid's
// own tenant — see App\Services\Bids\AppBidDirectory/BidWriter).
Route::get('projects/{project}/vendors/{vendor}/bids', [AppBidController::class, 'index'])->whereNumber(['project', 'vendor'])->name('bids.index');
Route::post('projects/{project}/vendors/{vendor}/bids', [AppBidController::class, 'store'])->whereNumber(['project', 'vendor'])->name('bids.store');
Route::delete('projects/{project}/vendors/{vendor}/bids/{bid}', [AppBidController::class, 'destroy'])->whereNumber(['project', 'vendor', 'bid'])->name('bids.destroy');

// TASKS — create, edit, delete and schedule. The planner's own read routes
// (planner.index, action-items.*) stay in routes/api.php.
Route::post('tasks', [AppTaskController::class, 'store'])->name('tasks.store');
Route::get('tasks/{task}', [AppTaskController::class, 'show'])->whereNumber('task')->name('tasks.show');
Route::post('tasks/{task}', [AppTaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
Route::delete('tasks/{task}', [AppTaskController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');

// TASK DEPENDENCIES — predecessor/lag/type, like the web's Dependencies
// panel (App\Livewire\Tasks\TaskCreate, via App\Services\Tasks\TaskDependencyWriter).
Route::get('tasks/{task}/available-predecessors', [AppTaskController::class, 'availablePredecessors'])->whereNumber('task')->name('tasks.available-predecessors');
Route::post('tasks/{task}/dependencies', [AppTaskController::class, 'storeDependency'])->whereNumber('task')->name('tasks.dependencies.store');
Route::delete('tasks/{task}/dependencies/{dependency}', [AppTaskController::class, 'destroyDependency'])->whereNumber(['task', 'dependency'])->name('tasks.dependencies.destroy');
