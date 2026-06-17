<?php

use App\Models\CallTranscript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert previously "failed" empty transcripts to the terminal "empty"
     * status so the `calls:process-recordings --retry-failed` scheduler stops
     * re-transcribing silent recordings every five minutes.
     */
    public function up(): void
    {
        if (! Schema::hasTable('call_transcripts')) {
            return;
        }

        DB::table('call_transcripts')
            ->where('status', CallTranscript::STATUS_FAILED)
            ->where('failure_reason', 'empty_transcript')
            ->update(['status' => CallTranscript::STATUS_EMPTY]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('call_transcripts')) {
            return;
        }

        DB::table('call_transcripts')
            ->where('status', CallTranscript::STATUS_EMPTY)
            ->where('failure_reason', 'empty_transcript')
            ->update(['status' => CallTranscript::STATUS_FAILED]);
    }
};
