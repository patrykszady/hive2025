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
        Schema::create('call_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_log_id')->constrained('call_logs')->cascadeOnDelete();
            $table->string('telnyx_recording_id')->nullable();
            $table->string('telnyx_transcription_id')->nullable();
            $table->string('engine')->nullable();
            $table->string('language', 8)->nullable();
            $table->longText('text')->nullable();
            $table->json('segments')->nullable();
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();

            $table->string('summary_model')->nullable();
            $table->text('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->json('topics')->nullable();
            $table->json('next_steps')->nullable();
            $table->string('sentiment', 16)->nullable();
            $table->string('caller_intent')->nullable();
            $table->timestamp('summarized_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_transcripts');
    }
};
