<?php

use App\Support\Leads\MissedYouTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Sorry we missed you": the lead email the office sends in one click when
 * a booked consult started and nobody met (Veera Chiranjeevi, Glenview,
 * 2026-11-05 11:00) — the homeowner picks new times from the same signed
 * link the Consult email carries. One per vendor that has a "Consult" lead
 * template, with that template's own signature; skipped where it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $consults = DB::table('templates')->where('type', 'lead')->where('name', 'Consult')->get();

        foreach ($consults as $consult) {
            $exists = DB::table('templates')
                ->where('vendor_id', $consult->vendor_id)
                ->where('type', 'lead')
                ->where('name', MissedYouTemplate::NAME)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('templates')->insert([
                'vendor_id' => $consult->vendor_id,
                'type' => 'lead',
                'name' => MissedYouTemplate::NAME,
                'subject' => MissedYouTemplate::subject(),
                'body' => MissedYouTemplate::bodyFrom($consult->body),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('templates')->where('type', 'lead')->where('name', MissedYouTemplate::NAME)->delete();
    }
};
