<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Leads that came in with a first name only never got a contact, even when
 * the message or the email address carried the surname (Valina,
 * valina.markhay@gmail.com, 2026-09-16). Intake reads both now; this
 * catches up the ones already here. Runs with the deploy; never fails it.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('leads:complete-names', ['--apply' => true]);
            Log::info('Migration: completed first-name-only leads', ['output' => trim(Artisan::output())]);
        } catch (\Throwable $e) {
            Log::error('Migration: completing lead names failed — run leads:complete-names --apply by hand', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // Contacts created are real people; nothing to undo.
    }
};
