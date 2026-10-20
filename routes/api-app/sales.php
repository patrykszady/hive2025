<?php

use App\Http\Controllers\Api\App\V1\LeadController as AppLeadController;
use App\Http\Controllers\Api\App\V1\LienWaiverController as AppLienWaiverController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Leads + Lien Waivers — the Hive Contractors app's Sales section
|--------------------------------------------------------------------------
|
| Mirrors App\Livewire\Leads\* and App\Livewire\LienWaivers\* exactly: same
| statuses, same filters, same side effects. See LeadPolicy/LienWaiverPolicy
| for the authorization this still enforces server-side regardless of what
| AppSectionPermissions hints the client to show.
*/

// 'badge', 'origins' and the bulk-* routes are registered ahead of the
// numeric {lead} routes for clarity, though whereNumber() alone already
// keeps any of them from being swallowed as an id.
Route::get('leads/badge', [AppLeadController::class, 'badge'])->name('leads.badge');
Route::get('leads/origins', [AppLeadController::class, 'origins'])->name('leads.origins');
Route::post('leads/bulk-status', [AppLeadController::class, 'bulkStatus'])->name('leads.bulk-status');
Route::post('leads/bulk-delete-impact', [AppLeadController::class, 'bulkDeleteImpact'])->name('leads.bulk-delete-impact');
Route::post('leads/bulk-delete', [AppLeadController::class, 'bulkDelete'])->name('leads.bulk-delete');
Route::get('leads', [AppLeadController::class, 'index'])->name('leads.index');
Route::post('leads', [AppLeadController::class, 'store'])->name('leads.store');
Route::get('leads/{lead}', [AppLeadController::class, 'show'])->whereNumber('lead')->name('leads.show');
// PUT, not PATCH — the shared app client (src/api/client.ts) only sends
// GET/POST/PUT/DELETE.
Route::put('leads/{lead}', [AppLeadController::class, 'update'])->whereNumber('lead')->name('leads.update');
Route::delete('leads/{lead}', [AppLeadController::class, 'destroy'])->whereNumber('lead')->name('leads.destroy');
Route::post('leads/{lead}/status', [AppLeadController::class, 'updateStatus'])->whereNumber('lead')->name('leads.status.update');
Route::post('leads/{lead}/consult-within-the-hour', [AppLeadController::class, 'updateConsultWithinTheHour'])->whereNumber('lead')->name('leads.consult-within-the-hour');
Route::post('leads/{lead}/schedule-link', [AppLeadController::class, 'scheduleLink'])->whereNumber('lead')->name('leads.schedule-link');
Route::post('leads/{lead}/text-schedule-link', [AppLeadController::class, 'textScheduleLink'])->whereNumber('lead')->name('leads.text-schedule-link');

// The Message composer — mirrors App\Livewire\Leads\LeadCreate's Message
// tab (see App\Services\Leads\LeadReplyComposer).
Route::get('leads/{lead}/reply-options', [AppLeadController::class, 'replyOptions'])->whereNumber('lead')->name('leads.reply-options');
Route::post('leads/{lead}/reply-preview', [AppLeadController::class, 'previewReply'])->whereNumber('lead')->name('leads.reply-preview');
Route::post('leads/{lead}/propose-date', [AppLeadController::class, 'proposeDate'])->whereNumber('lead')->name('leads.propose-date');
Route::post('leads/{lead}/reply', [AppLeadController::class, 'reply'])->whereNumber('lead')->name('leads.reply');
Route::post('leads/{lead}/send-consult-invite', [AppLeadController::class, 'sendConsultInvite'])->whereNumber('lead')->name('leads.send-consult-invite');

Route::get('lien-waivers/projects', [AppLienWaiverController::class, 'eligibleProjects'])->name('lien-waivers.projects');
Route::get('lien-waivers/sub-vendors', [AppLienWaiverController::class, 'subVendors'])->name('lien-waivers.sub-vendors');
Route::get('lien-waivers', [AppLienWaiverController::class, 'index'])->name('lien-waivers.index');
Route::post('lien-waivers', [AppLienWaiverController::class, 'store'])->name('lien-waivers.store');
Route::get('lien-waivers/{lienWaiver}', [AppLienWaiverController::class, 'show'])->whereNumber('lienWaiver')->name('lien-waivers.show');
Route::post('lien-waivers/{lienWaiver}/send', [AppLienWaiverController::class, 'send'])->whereNumber('lienWaiver')->name('lien-waivers.send');
Route::post('lien-waivers/{lienWaiver}/cancel', [AppLienWaiverController::class, 'cancel'])->whereNumber('lienWaiver')->name('lien-waivers.cancel');
Route::delete('lien-waivers/{lienWaiver}', [AppLienWaiverController::class, 'destroy'])->whereNumber('lienWaiver')->name('lien-waivers.destroy');
