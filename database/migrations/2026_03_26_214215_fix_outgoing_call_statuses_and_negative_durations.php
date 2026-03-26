<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix outgoing calls that were answered but incorrectly marked as "missed"
        DB::table('call_logs')
            ->where('direction', 'outgoing')
            ->where('status', 'missed')
            ->whereNotNull('answered_at')
            ->update(['status' => 'completed']);

        // Fix negative duration_seconds (Carbon 3 signed diffInSeconds)
        DB::table('call_logs')
            ->where('duration_seconds', '<', 0)
            ->update(['duration_seconds' => DB::raw('ABS(duration_seconds)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible — original incorrect data should not be restored
    }
};
