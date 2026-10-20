<?php

use App\Http\Controllers\Api\App\V1\HourController as AppHourController;
use App\Http\Controllers\Api\App\V1\TimesheetController as AppTimesheetController;
use App\Http\Controllers\Api\App\V1\VendorController as AppVendorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Field: Vendors, Hours and Timesheets
|--------------------------------------------------------------------------
|
| The Vendors tab, including "New Vendor"/"Edit Vendor"/"Add Existing
| Vendor" (App\Services\Vendors\AppVendorDirectory), the Hours tab
| (App\Services\Hours\AppHoursDay / HourEntryWriter), and the Timesheets tab,
| including "Revert Timesheet" (App\Services\Timesheets\AppTimesheetDirectory
| / AppPendingBalance) — see each controller's docblock for the web
| component it mirrors.
*/

// VENDORS — directory, detail, "New Vendor"/"Edit Vendor"/"Add Existing
// Vendor". 'types', 'search' and 'owner-lookup' are registered ahead of the
// numeric {vendor} routes for clarity, same reasoning as Timesheets' 'balance'.
Route::get('vendors/types', [AppVendorController::class, 'types'])->name('vendors.types');
Route::get('vendors/search', [AppVendorController::class, 'search'])->name('vendors.search');
Route::get('vendors/owner-lookup', [AppVendorController::class, 'ownerLookup'])->name('vendors.owner-lookup');
Route::get('vendors/payment-email-tracking', [AppVendorController::class, 'paymentEmailTracking'])->name('vendors.payment-email-tracking');
Route::get('vendors', [AppVendorController::class, 'index'])->name('vendors.index');
Route::post('vendors', [AppVendorController::class, 'store'])->name('vendors.store');
Route::get('vendors/{vendor}', [AppVendorController::class, 'show'])->whereNumber('vendor')->name('vendors.show');
Route::put('vendors/{vendor}', [AppVendorController::class, 'update'])->whereNumber('vendor')->name('vendors.update');
Route::post('vendors/{vendor}/link', [AppVendorController::class, 'link'])->whereNumber('vendor')->name('vendors.link');
Route::get('vendors/{vendor}/lien-waiver-email-tracking', [AppVendorController::class, 'lienWaiverEmailTracking'])->whereNumber('vendor')->name('vendors.lien-waiver-email-tracking');

// HOURS — the day the member taps, its offered projects, and saving the
// whole day at once. 'other-projects' is registered ahead of the bare
// 'hours' route for clarity, same reasoning as Projects' 'statuses'.
Route::get('hours/other-projects', [AppHourController::class, 'otherProjects'])->name('hours.other-projects');
Route::get('hours', [AppHourController::class, 'day'])->name('hours.day');
Route::post('hours', [AppHourController::class, 'store'])->name('hours.store');

// TIMESHEETS — confirmed weeks, one week's detail, and the signed-in
// member's own balance; the admin payable list lives at its own path so it
// never collides with a numeric {timesheet} id.
Route::get('timesheets/balance', [AppTimesheetController::class, 'balance'])->name('timesheets.balance');
Route::get('timesheets/payable', [AppTimesheetController::class, 'payable'])->name('timesheets.payable');
Route::get('timesheets', [AppTimesheetController::class, 'index'])->name('timesheets.index');
Route::get('timesheets/{timesheet}', [AppTimesheetController::class, 'show'])->whereNumber('timesheet')->name('timesheets.show');
Route::post('timesheets/{timesheet}/revert', [AppTimesheetController::class, 'revert'])->whereNumber('timesheet')->name('timesheets.revert');
