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
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('call_id')->unique()->index(); // Telnyx call_control_id
            $table->string('direction')->default('inbound'); // inbound, outbound, click_to_call
            $table->string('status')->default('initiated'); // initiated, ringing, answered, completed, failed, no_answer
            $table->string('from_number'); // E.164 format
            $table->string('to_number'); // E.164 format
            $table->string('caller_name')->nullable(); // Resolved caller name

            // Related entities
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Staff who made/received call
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('contact_user_id')->nullable(); // Client contact (User model with client role)

            // Call details
            $table->integer('duration_seconds')->nullable(); // Call duration
            $table->string('disconnect_cause')->nullable(); // Why call ended
            $table->text('notes')->nullable(); // Agent notes after call

            // Recording/voicemail
            $table->string('recording_url')->nullable();
            $table->boolean('has_voicemail')->default(false);

            // Raw webhook data for debugging
            $table->json('metadata')->nullable();

            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index(['direction', 'created_at']);
            $table->index(['from_number', 'created_at']);
            $table->index(['to_number', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
