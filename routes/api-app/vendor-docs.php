<?php

use App\Http\Controllers\Api\App\V1\VendorDocController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vendor Documents: additions on top of the read/upload routes
|--------------------------------------------------------------------------
|
| vendor-docs(.show)/store and the audit routes already live in
| routes/api-app/settings.php (another track's file) — this adds the
| "Request Documents" action alone, so settings.php stays untouched. See
| App\Services\VendorDocs\VendorDocRequester.
*/
Route::post('vendor-docs/{vendor}/request', [VendorDocController::class, 'request'])
    ->whereNumber('vendor')
    ->name('vendor-docs.request');
