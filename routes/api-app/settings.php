<?php

use App\Http\Controllers\Api\App\V1\AgentController;
use App\Http\Controllers\Api\App\V1\AuditController;
use App\Http\Controllers\Api\App\V1\CompanyEmailController;
use App\Http\Controllers\Api\App\V1\EmailTemplateController;
use App\Http\Controllers\Api\App\V1\OptionsController;
use App\Http\Controllers\Api\App\V1\PhoneSystemController;
use App\Http\Controllers\Api\App\V1\ReceiptPatternController;
use App\Http\Controllers\Api\App\V1\VendorCategoryController;
use App\Http\Controllers\Api\App\V1\VendorDocController;
use App\Http\Controllers\Api\App\V1\VendorMatchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings & admin: Options, Phone System, Email Templates, Email
| Accounts, Vendor Documents/Insurance, Categories, Vendor Match, Agents
|--------------------------------------------------------------------------
|
| Mirrors the web's settings pages exactly — see each controller's
| docblock for the Livewire component(s) and service it reuses.
| /phone-system/app-calling (a browser test page) and connecting a mailbox
| (Nylas OAuth) stay web-only.
*/

// OPTIONS — App\Livewire\Vendors\VendorOptions.
Route::get('options', [OptionsController::class, 'show'])->name('options.show');
Route::put('options', [OptionsController::class, 'update'])->name('options.update');
Route::post('options/logo', [OptionsController::class, 'uploadLogo'])->name('options.logo.store');
Route::delete('options/logo', [OptionsController::class, 'destroyLogo'])->name('options.logo.destroy');

// PHONE SYSTEM — App\Livewire\Vendors\PhoneSystemOptions. TTS preview stays web-only.
Route::get('phone-system', [PhoneSystemController::class, 'show'])->name('phone-system.show');
Route::put('phone-system', [PhoneSystemController::class, 'update'])->name('phone-system.update');

// EMAIL TEMPLATES — App\Livewire\EmailTemplates\{EmailTemplateIndex,EmailTemplateForm}.
Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
Route::post('email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
Route::get('email-templates/{template}', [EmailTemplateController::class, 'show'])->whereNumber('template')->name('email-templates.show');
Route::put('email-templates/{template}', [EmailTemplateController::class, 'update'])->whereNumber('template')->name('email-templates.update');
Route::delete('email-templates/{template}', [EmailTemplateController::class, 'destroy'])->whereNumber('template')->name('email-templates.destroy');

// EMAIL ACCOUNTS — App\Livewire\CompanyEmails\CompanyEmailsIndex. Connecting a mailbox (Nylas OAuth popup) stays web-only.
Route::get('company-emails', [CompanyEmailController::class, 'index'])->name('company-emails.index');
Route::post('company-emails/recheck', [CompanyEmailController::class, 'recheck'])->name('company-emails.recheck');

// VENDOR DOCUMENTS / INSURANCE — App\Livewire\VendorDocs\{VendorDocsIndex,VendorDocsCard,VendorDocCreate}.
// 'audit/*' is registered ahead of the numeric {vendor} routes for clarity,
// though whereNumber() alone already keeps it from being swallowed as an id.
Route::get('vendor-docs/audit/banks', [AuditController::class, 'banks'])->name('vendor-docs.audit.banks');
Route::get('vendor-docs/audit', [AuditController::class, 'show'])->name('vendor-docs.audit.show');
Route::get('vendor-docs', [VendorDocController::class, 'index'])->name('vendor-docs.index');
Route::get('vendor-docs/{vendor}', [VendorDocController::class, 'show'])->whereNumber('vendor')->name('vendor-docs.show');
Route::post('vendor-docs/{vendor}', [VendorDocController::class, 'store'])->whereNumber('vendor')->name('vendor-docs.store');

// CATEGORIES (and the web's separate Vendor Sheet Types page — see
// App\Services\Vendors\AppVendorCategorization's docblock) —
// App\Livewire\Categories\{CategoriesIndex,VendorCategoryCard}.
Route::get('categories', [VendorCategoryController::class, 'categories'])->name('categories.index');
Route::get('categories/vendors', [VendorCategoryController::class, 'index'])->name('categories.vendors.index');
Route::get('categories/vendors/{vendor}', [VendorCategoryController::class, 'show'])->whereNumber('vendor')->name('categories.vendors.show');
Route::put('categories/vendors/{vendor}/sheets-type', [VendorCategoryController::class, 'updateSheetsType'])->whereNumber('vendor')->name('categories.vendors.sheets-type.update');
Route::put('categories/vendors/{vendor}/category', [VendorCategoryController::class, 'updateCategory'])->whereNumber('vendor')->name('categories.vendors.category.update');
Route::delete('categories/vendors/{vendor}/category', [VendorCategoryController::class, 'clearCategory'])->whereNumber('vendor')->name('categories.vendors.category.destroy');
Route::post('categories/vendors/{vendor}/reassign', [VendorCategoryController::class, 'reassign'])->whereNumber('vendor')->name('categories.vendors.reassign');

// VENDOR MATCH — App\Livewire\ReceiptAccounts\{ReceiptAccountsIndex,ReceiptAccountVendorCreate}. "API login" (a receipt site's own sign-in) stays web-only.
Route::get('vendor-match/new-vendors', [VendorMatchController::class, 'newVendors'])->name('vendor-match.new-vendors');
Route::get('vendor-match', [VendorMatchController::class, 'index'])->name('vendor-match.index');
Route::get('vendor-match/{vendor}', [VendorMatchController::class, 'show'])->whereNumber('vendor')->name('vendor-match.show');
Route::put('vendor-match/{vendor}', [VendorMatchController::class, 'update'])->whereNumber('vendor')->name('vendor-match.update');

// AGENTS — Patryk (user id 1) only, exactly as the web — App\Livewire\Agents\AgentsIndex.
Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
Route::get('agents/vendor-docs', [AgentController::class, 'vendorDocs'])->name('agents.vendor-docs');

// RECEIPT PATTERNS — Patryk (user id 1) only (see ReceiptPatternController's
// own docblock for why reads are locked here too) — the email
// receipt-PARSING setup, App\Livewire\Receipts\ReceiptsIndex. 'vendors' is
// registered ahead of the numeric {receipt} routes for clarity, though
// whereNumber() alone already keeps it from being swallowed as an id.
Route::get('receipt-patterns/vendors', [ReceiptPatternController::class, 'vendors'])->name('receipt-patterns.vendors');
Route::get('receipt-patterns', [ReceiptPatternController::class, 'index'])->name('receipt-patterns.index');
Route::post('receipt-patterns', [ReceiptPatternController::class, 'store'])->name('receipt-patterns.store');
Route::get('receipt-patterns/{receipt}', [ReceiptPatternController::class, 'show'])->whereNumber('receipt')->name('receipt-patterns.show');
Route::put('receipt-patterns/{receipt}', [ReceiptPatternController::class, 'update'])->whereNumber('receipt')->name('receipt-patterns.update');
Route::delete('receipt-patterns/{receipt}', [ReceiptPatternController::class, 'destroy'])->whereNumber('receipt')->name('receipt-patterns.destroy');
