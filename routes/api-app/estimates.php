<?php

use App\Http\Controllers\Api\App\V1\EstimateAiController;
use App\Http\Controllers\Api\App\V1\EstimateContractSettingsController;
use App\Http\Controllers\Api\App\V1\EstimateController;
use App\Http\Controllers\Api\App\V1\EstimateEmailController;
use App\Http\Controllers\Api\App\V1\EstimateLineItemController;
use App\Http\Controllers\Api\App\V1\EstimatePaymentScheduleController;
use App\Http\Controllers\Api\App\V1\EstimateSectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Estimates — /api/app/v1/estimates
|--------------------------------------------------------------------------
|
| Admin-only and not for a 1099 vendor, exactly like the web
| (EstimatePolicy::viewAny/view/update all require vendor_role === 'Admin'
| and business_type !== '1099'). Sections, line items, email and the AI
| drafter are reused from the same services the web's Livewire components
| call — see EstimateController, EstimateSectionController,
| EstimateLineItemController, EstimateEmailController and
| EstimateAiController for the web component each mirrors.
*/

Route::get('estimates', [EstimateController::class, 'index'])->name('estimates.index');
Route::post('estimates', [EstimateController::class, 'store'])->name('estimates.store');
Route::get('estimates/{estimate}', [EstimateController::class, 'show'])->whereNumber('estimate')->name('estimates.show');
Route::delete('estimates/{estimate}', [EstimateController::class, 'destroy'])->whereNumber('estimate')->name('estimates.destroy');
Route::delete('estimates/{estimate}/permanent', [EstimateController::class, 'forceDelete'])->whereNumber('estimate')->name('estimates.force-delete');
Route::post('estimates/{estimate}/restore', [EstimateController::class, 'restore'])->whereNumber('estimate')->name('estimates.restore');
Route::post('estimates/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->whereNumber('estimate')->name('estimates.duplicate');
Route::get('estimates/{estimate}/pdf-url', [EstimateController::class, 'pdfUrl'])->whereNumber('estimate')->name('estimates.pdf-url');
Route::get('estimates/{estimate}/xlsx-url', [EstimateController::class, 'xlsxUrl'])->whereNumber('estimate')->name('estimates.xlsx-url');
Route::get('estimates/{estimate}/signed-contract-url', [EstimateController::class, 'signedContractUrl'])->whereNumber('estimate')->name('estimates.signed-contract-url');
Route::post('estimates/{estimate}/send-invite', [EstimateController::class, 'sendInvite'])->whereNumber('estimate')->name('estimates.send-invite');
Route::get('estimates/{estimate}/recent-changes', [EstimateController::class, 'recentChanges'])->whereNumber('estimate')->name('estimates.recent-changes');

Route::get('estimates/{estimate}/contract-settings', [EstimateContractSettingsController::class, 'show'])->whereNumber('estimate')->name('estimates.contract-settings.show');
Route::put('estimates/{estimate}/contract-settings', [EstimateContractSettingsController::class, 'update'])->whereNumber('estimate')->name('estimates.contract-settings.update');
Route::post('estimates/{estimate}/contract-settings/bids', [EstimateContractSettingsController::class, 'addBid'])->whereNumber('estimate')->name('estimates.contract-settings.bids.store');

Route::get('estimates/{estimate}/payment-schedule', [EstimatePaymentScheduleController::class, 'show'])->whereNumber('estimate')->name('estimates.payment-schedule.show');
Route::put('estimates/{estimate}/payment-schedule', [EstimatePaymentScheduleController::class, 'update'])->whereNumber('estimate')->name('estimates.payment-schedule.update');

Route::get('estimates/{estimate}/email-options', [EstimateEmailController::class, 'options'])->whereNumber('estimate')->name('estimates.email.options');
Route::post('estimates/{estimate}/email', [EstimateEmailController::class, 'send'])->whereNumber('estimate')->name('estimates.email.send');

Route::post('estimates/{estimate}/sections', [EstimateSectionController::class, 'store'])->whereNumber('estimate')->name('estimates.sections.store');
Route::put('estimates/{estimate}/sections/{section}', [EstimateSectionController::class, 'update'])->whereNumber(['estimate', 'section'])->name('estimates.sections.update');
Route::delete('estimates/{estimate}/sections/{section}', [EstimateSectionController::class, 'destroy'])->whereNumber(['estimate', 'section'])->name('estimates.sections.destroy');
Route::post('estimates/{estimate}/sections/{section}/restore', [EstimateSectionController::class, 'restore'])->whereNumber(['estimate', 'section'])->name('estimates.sections.restore');
Route::post('estimates/{estimate}/sections/{section}/duplicate', [EstimateSectionController::class, 'duplicate'])->whereNumber(['estimate', 'section'])->name('estimates.sections.duplicate');
Route::get('estimates/{estimate}/sections/{section}/duplicate-targets', [EstimateSectionController::class, 'duplicateTargets'])->whereNumber(['estimate', 'section'])->name('estimates.sections.duplicate-targets');
Route::post('estimates/{estimate}/sections/{section}/duplicate-to-estimate', [EstimateSectionController::class, 'duplicateToEstimate'])->whereNumber(['estimate', 'section'])->name('estimates.sections.duplicate-to-estimate');
Route::post('estimates/{estimate}/sections/{section}/ai-draft', [EstimateAiController::class, 'draft'])->whereNumber(['estimate', 'section'])->name('estimates.sections.ai-draft');

Route::post('estimates/{estimate}/line-items', [EstimateLineItemController::class, 'store'])->whereNumber('estimate')->name('estimates.line-items.store');
Route::put('estimates/{estimate}/line-items/{lineItem}', [EstimateLineItemController::class, 'update'])->whereNumber(['estimate', 'lineItem'])->name('estimates.line-items.update');
Route::delete('estimates/{estimate}/line-items/{lineItem}', [EstimateLineItemController::class, 'destroy'])->whereNumber(['estimate', 'lineItem'])->name('estimates.line-items.destroy');
Route::post('estimates/{estimate}/line-items/{lineItem}/restore', [EstimateLineItemController::class, 'restore'])->whereNumber(['estimate', 'lineItem'])->name('estimates.line-items.restore');
