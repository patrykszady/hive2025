<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change-order bids that were auto-created for estimate sections on Active
 * projects BEFORE the "a section is only a change order once the contract
 * exists" rule (2026-07-24). With no signed contract the finances already sum
 * every section as the estimate, so such a bid counted its section twice —
 * project 382 showed a $3,927 change order for a section of its own $6,297
 * estimate. Unlink the sections and drop the bids.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stale = DB::table('bids as b')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->where('b.type', '!=', 1)
            ->whereNull('p.deleted_at')
            ->whereColumn('b.vendor_id', 'p.belongs_to_vendor_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bids as base')
                ->whereColumn('base.project_id', 'b.project_id')
                ->whereColumn('base.vendor_id', 'p.belongs_to_vendor_id')
                ->where('base.type', 1))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('estimate_sections as s')
                ->whereColumn('s.bid_id', 'b.id')
                ->whereNull('s.deleted_at'))
            ->pluck('b.id');

        foreach ($stale as $bidId) {
            $sections = DB::table('estimate_sections')->where('bid_id', $bidId)->update(['bid_id' => null]);
            DB::table('bids')->where('id', $bidId)->delete();
            Log::info("unlink_pre_contract_change_orders: dropped bid {$bidId}, unlinked {$sections} section(s)");
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: the bids were double-counting artefacts.
    }
};
