<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('call_transcripts', function (Blueprint $table): void {
            // AssemblyAI Speaker Identification mapping of raw diarization
            // labels ("A", "B", ...) to resolved real-world names.
            $table->json('speaker_map')->nullable()->after('segments');
            // AssemblyAI Audio Intelligence outputs (entities, highlights,
            // chapters, IAB topics, sentiment) kept together as one blob.
            $table->json('intelligence')->nullable()->after('caller_intent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_transcripts', function (Blueprint $table): void {
            $table->dropColumn(['speaker_map', 'intelligence']);
        });
    }
};
