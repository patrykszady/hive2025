<?php

use App\Http\Controllers\Api\App\V1\CheckController as AppCheckController;
use App\Http\Controllers\Api\App\V1\PaymentController as AppPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments & Checks — /api/app/v1/payments, /api/app/v1/checks
|--------------------------------------------------------------------------
|
| Payments is Admin-only end to end (PaymentPolicy::viewAny/view/create all
| require vendor_role === 'Admin'); Checks is open to the whole team, each
| scoped to what Check's own CheckScope lets them see. See PaymentController
| and CheckController for the web components these mirror.
*/

// "eligible-projects" is registered ahead of the numeric {payment} route so
// it is never swallowed as an id — whereNumber() alone already prevents
// that, but this keeps the ordering obvious for the next person reading it.
Route::get('payments/eligible-projects', [AppPaymentController::class, 'eligibleProjects'])->name('payments.eligible-projects');
Route::get('payments', [AppPaymentController::class, 'index'])->name('payments.index');
Route::post('payments', [AppPaymentController::class, 'store'])->name('payments.store');
Route::get('payments/{payment}', [AppPaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');

Route::get('checks', [AppCheckController::class, 'index'])->name('checks.index');
Route::get('checks/{check}', [AppCheckController::class, 'show'])->whereNumber('check')->name('checks.show');
