<?php

use App\Models\Lead;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Leads still at New or Replied although a consult is on the calendar for
 * them — a consult booked or moved in the task form never converted the
 * lead, and re-picking through the link put a booked lead back to New
 * (Carri Taraszka and Jeanne Bondi, 2026-09-16; both re-picks were tests
 * right after the confirmation). TaskObserver and the composer convert
 * from now on; this converts the ones already on the books, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        $leads = Lead::withoutGlobalScopes()
            ->whereLatestStatus(['New', 'Replied'])
            ->whereNotNull('user_id')
            ->get();

        foreach ($leads as $lead) {
            if ($lead->bookedConsultTasks()->isNotEmpty() && $lead->setStatus('Won')) {
                Log::info('Migration: lead marked Won for its booked consult', ['lead_id' => $lead->id]);
            }
        }
    }

    public function down(): void
    {
        // A conversion is not something to put back.
    }
};
