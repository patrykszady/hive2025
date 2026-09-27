<?php

use App\Http\Controllers\Api\Admin\V1\LeadController;
use App\Support\AdminApi;
use Illuminate\Support\Facades\Route;

// Leads screen = Hive's sign-up funnel. See LeadController's docblock for
// why a User row (not App\Models\Lead) is the right source, and why
// status-update/destroy are refused. stats before {lead} so it's never
// read as a lead id.
AdminApi::declare('leads');

Route::get('leads/stats', [LeadController::class, 'stats'])->name('leads.stats');
Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
Route::patch('leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('leads.update-status');
Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
