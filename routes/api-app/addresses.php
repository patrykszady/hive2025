<?php

use App\Http\Controllers\Api\App\V1\AddressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Addresses: autocomplete for every form with an address field
|--------------------------------------------------------------------------
|
| Shared across domains (vendors, team members, and — once those tracks
| wire this in — clients/projects/leads too), exactly like the web's
| App\Traits\HandlesAddresses is reused by every address-bearing Livewire
| form. See App\Http\Controllers\Api\App\V1\AddressController and
| App\Services\GeoapifyService.
*/
Route::get('addresses/autocomplete', [AddressController::class, 'autocomplete'])
    ->middleware('throttle:30,1')
    ->name('addresses.autocomplete');

Route::get('addresses/resolve', [AddressController::class, 'resolve'])
    ->middleware('throttle:60,1')
    ->name('addresses.resolve');
