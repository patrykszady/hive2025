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
        Schema::create('client_schedule_sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Client user who received the SMS
            $table->string('type'); // 'today', 'tomorrow', 'changed'
            $table->date('target_date'); // The date the SMS is about
            $table->string('tasks_hash')->nullable(); // Hash of task IDs + times to detect changes
            $table->timestamps();

            // Prevent duplicate sends for the same project/user/type/date combo
            $table->unique(['project_id', 'user_id', 'type', 'target_date'], 'client_sms_unique');

            // Index for querying recent sends (for throttling "changed" type)
            $table->index(['project_id', 'user_id', 'type', 'created_at'], 'client_sms_throttle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_schedule_sms_logs');
    }
};
