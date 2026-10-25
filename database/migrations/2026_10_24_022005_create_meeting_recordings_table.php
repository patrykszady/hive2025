<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per in-person meeting recorded from Hive's Record button
     * (App\Livewire\Meetings\MeetingRecorder): the audio uploaded in pieces
     * while it records — or a file recorded elsewhere (Voice Memos, a
     * handheld recorder) uploaded afterwards — then its transcript, summary
     * and action items, which feed the project's planner like a call's do.
     */
    public function up(): void
    {
        Schema::create('meeting_recordings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('recorded_by_user_id')->index();
            $table->string('status', 24)->index();
            $table->string('source', 16)->default('browser')->index();
            $table->timestamp('consent_confirmed_at')->nullable();
            $table->string('microphone')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('mime_type', 64)->nullable();
            $table->string('audio_disk', 32)->nullable();
            $table->string('audio_path')->nullable();
            $table->string('language', 8)->nullable();
            $table->longText('transcript')->nullable();
            $table->json('segments')->nullable();
            $table->text('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->json('topics')->nullable();
            $table->json('next_steps')->nullable();
            $table->string('summary_model')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->string('assemblyai_id')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('purge_after')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_recordings');
    }
};
