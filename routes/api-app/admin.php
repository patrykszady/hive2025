<?php

use App\Http\Controllers\Api\App\V1\LoginAsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin — log in as another team member
|--------------------------------------------------------------------------
|
| The web's Global Actions → "Login As User" (App\Livewire\Users\AdminLoginAsUser,
| UserPolicy::admin_login_as_user — Patryk only). The app swaps to a short-lived
| token for the chosen team member and keeps Patryk's own to return to.
*/
Route::get('admin/login-as/users', [LoginAsController::class, 'users'])->name('admin.login-as.users');
Route::post('admin/login-as', [LoginAsController::class, 'store'])->name('admin.login-as.store');
