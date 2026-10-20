<?php

use App\Http\Controllers\Api\App\V1\ExpenseAssociationController;
use App\Http\Controllers\Api\App\V1\ExpenseController;
use App\Http\Controllers\Api\App\V1\ExpenseVendorOptionsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The app's Expenses section
|--------------------------------------------------------------------------
|
| Mirrors App\Livewire\Expenses\{ExpenseIndex,ExpenseShow,ExpenseCreate} —
| see App\Services\Expenses\AppExpenseDirectory for the shared query/write
| logic and App\Http\Controllers\Api\App\V1\ExpenseController for the
| routes below. 'vendor-options' is registered ahead of the numeric
| {expense} routes for clarity, though whereNumber() alone already keeps
| it from being swallowed as an id.
*/
Route::get('expenses/vendor-options', ExpenseVendorOptionsController::class)->name('expenses.vendor-options');
Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->name('expenses.show');
// POST, not PUT — see ExpenseController::update()'s docblock (a receipt
// photo makes this a multipart request, which PHP won't populate $_FILES
// for on a PUT).
Route::post('expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('expenses.update');
Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');

// "Linked Expenses" (App\Livewire\Expenses\ExpensesAssociated) and removing
// an expense from a check it was paid by (ExpenseShow::removeFromCheck) —
// see ExpenseAssociationController and the App\Services\Expenses\
// ExpenseAssociationService it shares with those Livewire components.
Route::get('expenses/{expense}/associate-options', [ExpenseAssociationController::class, 'options'])->whereNumber('expense')->name('expenses.associate-options');
Route::post('expenses/{expense}/associate', [ExpenseAssociationController::class, 'associate'])->whereNumber('expense')->name('expenses.associate');
Route::delete('expenses/{expense}/checks/{check}', [ExpenseAssociationController::class, 'removeFromCheck'])->whereNumber('expense')->whereNumber('check')->name('expenses.checks.destroy');
