<?php

use App\Http\Controllers\Api\App\V1\ClientController as AppClientController;
use App\Http\Controllers\Api\App\V1\UserController as AppUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Clients: create/edit a client and the client's people
|--------------------------------------------------------------------------
|
| The list/detail/thread routes for Clients already live in routes/api.php
| (ClientController@index/show/thread) — these add the write side, reusing
| that same controller. See App\Services\Clients\AppClientDirectory.
*/
Route::post('clients/lookup-person', [AppClientController::class, 'lookupPerson'])->name('clients.lookup-person');
Route::post('clients', [AppClientController::class, 'store'])->name('clients.store');
Route::put('clients/{client}', [AppClientController::class, 'update'])->whereNumber('client')->name('clients.update');
Route::post('clients/{client}/people', [AppClientController::class, 'addPerson'])->whereNumber('client')->name('clients.people.store');
Route::delete('clients/{client}', [AppClientController::class, 'destroy'])->whereNumber('client')->name('clients.destroy');

/*
|--------------------------------------------------------------------------
| Users: the team, a member's detail, and the signed-in person's own
| profile + notification settings
|--------------------------------------------------------------------------
|
| See App\Services\Users\AppTeamDirectory and
| App\Http\Controllers\Api\App\V1\UserController.
*/
Route::get('users', [AppUserController::class, 'index'])->name('users.index');
Route::post('users', [AppUserController::class, 'store'])->name('users.store');
Route::get('users/me', [AppUserController::class, 'me'])->name('users.me');
Route::get('users/me/notification-settings', [AppUserController::class, 'notificationSettings'])->name('users.notification-settings');
Route::put('users/me/notification-settings', [AppUserController::class, 'updateNotificationSettings'])->name('users.notification-settings.update');
Route::get('users/{user}', [AppUserController::class, 'show'])->whereNumber('user')->name('users.show');
Route::put('users/{user}', [AppUserController::class, 'update'])->whereNumber('user')->name('users.update');
Route::delete('users/{user}', [AppUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
