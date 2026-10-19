<?php

use App\Http\Controllers\Api\App\V1\BankController;
use App\Http\Controllers\Api\App\V1\DistributionController;
use App\Http\Controllers\Api\App\V1\SheetController;
use App\Http\Controllers\Api\App\V1\TransactionMatchController;
use App\Http\Controllers\Api\App\V1\VendorTransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The app's books: Banks, Distributions, Sheets, Match Vendor
|--------------------------------------------------------------------------
|
| GS Construction's own Admins only — every route below authorizes against
| the same policy its mirrored Livewire component does
| (BankPolicy/DistributionPolicy/SheetPolicy/TransactionBulkMatchPolicy, all
| vendor_role === 'Admin'). See App\Livewire\{Banks,Distributions,Sheets,
| Transactions}\* for the web pages these mirror, and
| App\Services\{Banks,Distributions,Sheets,Transactions}\* for the shared
| figures/logic both read.
*/

// Banks — read-only: accounts, balances, sync errors and uncleared checks.
// Reconnecting a bank (Plaid Link) and downloading statements both need a
// browser SDK, so they stay web-only ("Open in Hive").
Route::get('banks', [BankController::class, 'index'])->name('banks.index');
Route::get('banks/{bank}', [BankController::class, 'show'])->whereNumber('bank')->name('banks.show');

// Distributions — list, detail (vendor breakdown + projects, trailing
// year), and creating one (an eligible team member + a name). Assigning a
// project's profit split across distributions stays web-only.
Route::get('distributions/eligible-users', [DistributionController::class, 'eligibleUsers'])->name('distributions.eligible-users');
Route::get('distributions', [DistributionController::class, 'index'])->name('distributions.index');
Route::post('distributions', [DistributionController::class, 'store'])->name('distributions.store');
Route::get('distributions/{distribution}', [DistributionController::class, 'show'])->whereNumber('distribution')->name('distributions.show');

// Sheets — read-only profit & loss figures. Exporting (CSV/PDF) and
// assigning an uncategorized expense's category both stay web-only.
Route::get('sheets', [SheetController::class, 'index'])->name('sheets.index');
Route::get('sheets/monthly', [SheetController::class, 'monthly'])->name('sheets.monthly');
Route::get('sheets/show', [SheetController::class, 'show'])->name('sheets.show');

// Match Vendor (a "Global Action" on the web, visible only to Patryk) —
// the unmatched-transaction/receipt cards and matching a card to a vendor.
// AI-assisted suggestions stay web-only.
Route::get('transactions/unmatched', [TransactionMatchController::class, 'unmatched'])->name('transactions.unmatched');
Route::post('transactions/match', [TransactionMatchController::class, 'match'])->name('transactions.match');
Route::post('transactions/match-expense', [TransactionMatchController::class, 'matchExpense'])->name('transactions.match-expense');

// The bulk-match rules list behind Match Vendor (VendorTransaction rows —
// "what pattern maps to which vendor"), not to be confused with
// TransactionBulkMatch (App\Livewire\BulkMatch\*), which has no web route
// at all (dead code: BulkMatchIndex::store() calls dd()) and is not
// mirrored here.
Route::get('vendor-transactions', [VendorTransactionController::class, 'index'])->name('vendor-transactions.index');
Route::post('vendor-transactions', [VendorTransactionController::class, 'store'])->name('vendor-transactions.store');
Route::put('vendor-transactions/{vendorTransaction}', [VendorTransactionController::class, 'update'])->whereNumber('vendorTransaction')->name('vendor-transactions.update');
Route::delete('vendor-transactions/{vendorTransaction}', [VendorTransactionController::class, 'destroy'])->whereNumber('vendorTransaction')->name('vendor-transactions.destroy');
