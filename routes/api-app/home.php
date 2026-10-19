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
