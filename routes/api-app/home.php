<?php

use App\Http\Controllers\Api\App\V1\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Home — the app's landing tab
|--------------------------------------------------------------------------
|
| Mirrors the web Hub (App\Livewire\Dashboard\DashboardShow, UserTasks,
| VendorTasks) — see App\Services\Dashboard\AppHomeAgenda and
| App\Http\Controllers\Api\App\V1\HomeController.
*/
Route::get('home', [HomeController::class, 'show'])->name('home.show');
Route::post('home/tasks/{task}/checklist/{index}/toggle', [HomeController::class, 'toggleChecklistItem'])
    ->whereNumber('task')
    ->whereNumber('index')
    ->name('home.tasks.checklist.toggle');
