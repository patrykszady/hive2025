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

// "eligible-projects"/"filter-options" are registered ahead of the numeric
// {payment} route so they are never swallowed as an id — whereNumber()
// alone already prevents that, but this keeps the ordering obvious for the
// next person reading it.
Route::get('payments/eligible-projects', [AppPaymentController::class, 'eligibleProjects'])->name('payments.eligible-projects');
// The standalone Payments list's Project/Client filter dropdown options —
// mirrors PaymentsIndex::mount()'s own cached lists.
Route::get('payments/filter-options', [AppPaymentController::class, 'filterOptions'])->name('payments.filter-options');
Route::get('payments', [AppPaymentController::class, 'index'])->name('payments.index');
Route::post('payments', [AppPaymentController::class, 'store'])->name('payments.store');
Route::get('payments/{payment}', [AppPaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');
Route::put('payments/{payment}', [AppPaymentController::class, 'update'])->whereNumber('payment')->name('payments.update');
Route::delete('payments/{payment}', [AppPaymentController::class, 'destroy'])->whereNumber('payment')->name('payments.destroy');

Route::get('checks', [AppCheckController::class, 'index'])->name('checks.index');
// Ahead of checks/{check} in spirit only — whereNumber() already keeps a
// non-numeric path from being swallowed as an id.
Route::get('checks/bank-accounts', [AppCheckController::class, 'bankAccountOptions'])->name('checks.bank-accounts');
// The standalone Checks list's Bank filter dropdown options — mirrors
// ChecksIndex::mount()'s $banks query, grouped the same way its
// filter-fields partial does.
Route::get('checks/bank-options', [AppCheckController::class, 'bankOptions'])->name('checks.bank-options');
Route::get('checks/{check}', [AppCheckController::class, 'show'])->whereNumber('check')->name('checks.show');
// Mirrors App\Livewire\Forms\CheckForm::update()/delete() via
// App\Services\Checks\CheckUpdateService — see CheckController's docblock.
Route::put('checks/{check}', [AppCheckController::class, 'update'])->whereNumber('check')->name('checks.update');
Route::delete('checks/{check}', [AppCheckController::class, 'destroy'])->whereNumber('check')->name('checks.destroy');
// Mirrors App\Livewire\Checks\CheckShow::uploadCheckImage().
Route::post('checks/{check}/images', [AppCheckController::class, 'uploadImage'])->whereNumber('check')->name('checks.images.store');
