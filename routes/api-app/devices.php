<?php

use App\Http\Controllers\Api\App\V1\PushTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Push tokens — the app's Expo device registration
|--------------------------------------------------------------------------
|
| Registers/unregisters this device's Expo push token (App\Models\
| PushToken), which App\Jobs\SendExpoPushForNotification fans every new
| AppNotification out to. Sign-out also cleans this device's row up
| server-side (AuthController::logout()), belt-and-suspenders with the
| app's own DELETE call here.
*/
Route::post('push-tokens', [PushTokenController::class, 'store'])->name('push-tokens.store');
Route::delete('push-tokens', [PushTokenController::class, 'destroy'])->name('push-tokens.destroy');
