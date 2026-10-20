<?php

use App\Http\Controllers\Api\App\V1\BankController;
use App\Http\Controllers\Api\App\V1\DistributionController;
use App\Http\Controllers\Api\App\V1\DistributionProjectAssignmentController;
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

// Banks — accounts, balances, sync errors, uncleared checks, and
// downloading the latest Plaid statement (statementUrl(), a signed
// download link — see BankController's own docblock). Reconnecting a bank
// (Plaid Link) and the "Update/New Bank Account" flows need the Plaid Link
// SDK in a browser, so those stay web-only ("Open in Hive").
Route::get('banks', [BankController::class, 'index'])->name('banks.index');
Route::get('banks/{bank}', [BankController::class, 'show'])->whereNumber('bank')->name('banks.show');
Route::get('banks/{bank}/statement-url', [BankController::class, 'statementUrl'])->whereNumber('bank')->name('banks.statement-url');

// Distributions — list, detail (vendor breakdown + projects, trailing
// year), creating one (an eligible team member + a name), and assigning a
// project's profit split across distributions (individually or in bulk —
// see DistributionProjectAssignmentController).
Route::get('distributions/eligible-users', [DistributionController::class, 'eligibleUsers'])->name('distributions.eligible-users');
Route::get('distributions', [DistributionController::class, 'index'])->name('distributions.index');
Route::post('distributions', [DistributionController::class, 'store'])->name('distributions.store');
Route::get('distributions/{distribution}', [DistributionController::class, 'show'])->whereNumber('distribution')->name('distributions.show');

// "assignable-projects"/"assignment-options"/"assignment/bulk" are
// registered ahead of distributions/{distribution} in spirit only —
// whereNumber() on that route already keeps them from being swallowed as
// an id, since none of these is numeric.
Route::get('distributions/assignable-projects', [DistributionProjectAssignmentController::class, 'projects'])->name('distributions.assignable-projects');
Route::get('distributions/assignment-options', [DistributionProjectAssignmentController::class, 'options'])->name('distributions.assignment-options');
Route::post('distributions/assignment/bulk', [DistributionProjectAssignmentController::class, 'assignBulk'])->name('distributions.assignment.bulk');
Route::get('distributions/projects/{project}/assignment', [DistributionProjectAssignmentController::class, 'show'])->whereNumber('project')->name('distributions.projects.assignment.show');
Route::put('distributions/projects/{project}/assignment', [DistributionProjectAssignmentController::class, 'assign'])->whereNumber('project')->name('distributions.projects.assignment.update');

// Sheets — profit & loss figures, exporting (CSV/PDF, as a signed download
// link — see SheetController's own docblock) and assigning an
// uncategorized expense's category.
Route::get('sheets', [SheetController::class, 'index'])->name('sheets.index');
Route::get('sheets/monthly', [SheetController::class, 'monthly'])->name('sheets.monthly');
Route::get('sheets/show', [SheetController::class, 'show'])->name('sheets.show');
Route::get('sheets/export/csv-url', [SheetController::class, 'exportCsvUrl'])->name('sheets.export.csv-url');
Route::get('sheets/export/pdf-url', [SheetController::class, 'exportPdfUrl'])->name('sheets.export.pdf-url');
Route::put('sheets/categorize/{expense}', [SheetController::class, 'categorizeExpense'])->whereNumber('expense')->name('sheets.categorize');

// Match Vendor (a "Global Action" on the web, visible only to Patryk) —
// the unmatched-transaction/receipt cards, the vendor picker's options,
// matching a card to a vendor, and the AI-assisted suggestion flow
// (suggest then apply).
Route::get('transactions/unmatched', [TransactionMatchController::class, 'unmatched'])->name('transactions.unmatched');
Route::get('transactions/vendor-options', [TransactionMatchController::class, 'vendorOptions'])->name('transactions.vendor-options');
Route::post('transactions/match', [TransactionMatchController::class, 'match'])->name('transactions.match');
Route::post('transactions/match-expense', [TransactionMatchController::class, 'matchExpense'])->name('transactions.match-expense');
Route::post('transactions/suggest-vendor', [TransactionMatchController::class, 'suggestVendor'])->name('transactions.suggest-vendor');
Route::post('transactions/apply-suggestion', [TransactionMatchController::class, 'applySuggestion'])->name('transactions.apply-suggestion');

// The bulk-match rules list behind Match Vendor (VendorTransaction rows —
// "what pattern maps to which vendor"), not to be confused with
// TransactionBulkMatch (App\Livewire\BulkMatch\*), which has no web route
// at all (dead code: BulkMatchIndex::store() calls dd()) and is not
// mirrored here.
Route::get('vendor-transactions', [VendorTransactionController::class, 'index'])->name('vendor-transactions.index');
Route::post('vendor-transactions', [VendorTransactionController::class, 'store'])->name('vendor-transactions.store');
Route::put('vendor-transactions/{vendorTransaction}', [VendorTransactionController::class, 'update'])->whereNumber('vendorTransaction')->name('vendor-transactions.update');
Route::delete('vendor-transactions/{vendorTransaction}', [VendorTransactionController::class, 'destroy'])->whereNumber('vendorTransaction')->name('vendor-transactions.destroy');
