<?php

use App\Http\Controllers\Api\Admin\V1\AnalyticsController;
use SsSystems\Platform\Http\Admin\CapabilityRegistry;
use Illuminate\Support\Facades\Route;

// Analytics screen — reads SsSystems\Platform\Pulse's site_events, not a
// TrackedEvent table. See AnalyticsController's docblock for the
// phone/email/cta/form mapping.
CapabilityRegistry::declare('analytics');

Route::get('analytics/summary', [AnalyticsController::class, 'summary'])->name('analytics.summary');
Route::get('analytics/events', [AnalyticsController::class, 'events'])->name('analytics.events');
