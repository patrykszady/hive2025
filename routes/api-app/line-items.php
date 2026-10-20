<?php

use App\Http\Controllers\Api\App\V1\LineItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Line item catalog — /api/app/v1/line-items
|--------------------------------------------------------------------------
|
| Admin-only (LineItemPolicy::viewAny/view/create all require vendor_role
| === 'Admin'). LineItem carries no tenant scope of its own, so
| LineItemController manually scopes every lookup to the caller's vendor,
| same as App\Livewire\LineItems\LineItemsIndex/LineItemCreate.
*/

Route::get('line-items', [LineItemController::class, 'index'])->name('line-items.index');
Route::post('line-items', [LineItemController::class, 'store'])->name('line-items.store');
Route::get('line-items/{lineItem}', [LineItemController::class, 'show'])->whereNumber('lineItem')->name('line-items.show');
Route::put('line-items/{lineItem}', [LineItemController::class, 'update'])->whereNumber('lineItem')->name('line-items.update');
// "Previously used" allowance suggestions — mirrors EstimateLineItemCreate::
// previousAllowances(), the autocomplete under each allowance row when
// adding this catalog item to an estimate.
Route::get('line-items/{lineItem}/allowance-suggestions', [LineItemController::class, 'allowanceSuggestions'])->whereNumber('lineItem')->name('line-items.allowance-suggestions');
