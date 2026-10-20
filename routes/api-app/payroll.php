<?php

use App\Http\Controllers\Api\App\V1\AutoReceiptController;
use App\Http\Controllers\Api\App\V1\TimesheetCreateController;
use App\Http\Controllers\Api\App\V1\TimesheetPaymentController;
use App\Http\Controllers\Api\App\V1\VendorPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payroll & paying vendors
|--------------------------------------------------------------------------
|
| Confirming a week's Hours into a Timesheet, paying a team member, paying
| a vendor, and the read-only "Recent Auto Receipts" queue. Every write
| here shares its exact logic with the matching web Livewire form — see
| App\Services\Timesheets\{TimesheetConfirmation,TimesheetPaymentOptions,
| TimesheetPaymentRecorder} and App\Services\Vendors\{VendorPaymentOptions,
| VendorPaymentRecorder}.
|
| 'create' and 'payment' are registered as literal path segments (3-segment
| paths), so they never collide with field.php's 2-segment
| timesheets/{timesheet} (whereNumber) or vendors/{vendor} routes.
*/

// TIMESHEETS — confirm a week's Hours into a Timesheet (open to any team
// member, exactly like the web's TimesheetCreate — see
// TimesheetConfirmation's docblock), then pay a team member (Admin-only to
// submit; viewing the form is open to the member themself too).
// 'pending' is registered ahead of the numeric {hour} route for clarity,
// though its own literal 2-vs-3-segment path already keeps it from being
// swallowed as an hour id.
Route::get('timesheets/create', [TimesheetCreateController::class, 'pending'])->name('timesheets.create.pending');
Route::get('timesheets/create/{hour}', [TimesheetCreateController::class, 'show'])->whereNumber('hour')->name('timesheets.create.show');
Route::post('timesheets/create/{hour}', [TimesheetCreateController::class, 'store'])->whereNumber('hour')->name('timesheets.create.store');

Route::get('timesheets/payment/{user}', [TimesheetPaymentController::class, 'show'])->whereNumber('user')->name('timesheets.payment.show');
Route::post('timesheets/payment/{user}', [TimesheetPaymentController::class, 'store'])->whereNumber('user')->name('timesheets.payment.store');

// VENDORS — pay a vendor (Admin-only, non-1099, same CheckPolicy::create
// gate on both the form and the submit).
Route::get('vendors/{vendor}/payment', [VendorPaymentController::class, 'show'])->whereNumber('vendor')->name('vendors.payment.show');
Route::post('vendors/{vendor}/payment', [VendorPaymentController::class, 'store'])->whereNumber('vendor')->name('vendors.payment.store');

// RECEIPTS — the "Recent Auto Receipts" review queue (read-only; see
// App\Services\Receipts\AppAutoReceiptsQueue's docblock for what stays
// web-only).
Route::get('receipts/auto', [AutoReceiptController::class, 'index'])->name('receipts.auto.index');
Route::get('receipts/auto/{receipt}', [AutoReceiptController::class, 'show'])->whereNumber('receipt')->name('receipts.auto.show');
