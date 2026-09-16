<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Consultations still on the calendar for leads that were removed before
 * removal cancelled them (lead 170 / task 1146, 2026-09-15). Removing a
 * lead now cancels its consult; this runs the same clean-up once, with the
 * deploy, for the ones already left behind — the invite is withdrawn by
 * the queued calendar job the task deletion dispatches.
 *
 * Never fails the deploy: a problem here is logged and the command
 * (leads:cancel-orphaned-consults) can be run by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('leads:cancel-orphaned-consults');
            Log::info('Migration: cancelled consults of removed leads', ['output' => trim(Artisan::output())]);
        } catch (\Throwable $e) {
            Log::error('Migration: cancelling consults of removed leads failed — run leads:cancel-orphaned-consults by hand', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // A cancelled consult is not something to put back.
    }
};
