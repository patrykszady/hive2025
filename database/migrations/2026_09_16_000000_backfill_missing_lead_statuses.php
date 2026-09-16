<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A lead with no status row has no pipeline stage: /leads can only offer
 * "Set status" for it. Texting the consult scheduling link from Messages
 * minted leads that way until 2026-09-15 (lead 171); the path now writes
 * New like every other. This gives the leads already born without one
 * their New, dated when they arrived. Runs with the deploy's migrate, so
 * production needs no separate step; idempotent, so re-running is free.
 * (leads:backfill-missing-statuses does the same by hand.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $leads = DB::table('leads')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('lead_statuses')
                ->whereColumn('lead_statuses.lead_id', 'leads.id'))
            ->get(['id', 'belongs_to_vendor_id', 'date', 'created_at']);

        foreach ($leads as $lead) {
            $at = $lead->date ?? $lead->created_at ?? now();

            DB::table('lead_statuses')->insert([
                'lead_id' => $lead->id,
                'title' => 'New',
                'belongs_to_vendor_id' => $lead->belongs_to_vendor_id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: a stage a lead should always have had.
    }
};
