<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AssemblyAI's own confidence in a transcript (0–1). Below the configured
 * threshold a call pinned to English is transcribed once more in Polish
 * (TranscribeCallRecording::retryInOtherLanguage); keeping the number lets
 * the threshold be tuned from real calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_transcripts', function (Blueprint $table) {
            $table->decimal('confidence', 4, 3)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('call_transcripts', function (Blueprint $table) {
            $table->dropColumn('confidence');
        });
    }
};
