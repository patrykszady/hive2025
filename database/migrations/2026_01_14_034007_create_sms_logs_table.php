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
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel'); // client, team, vendor
            $table->string('type'); // today, tomorrow, changed, reminder, update, availability
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Recipient
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('target_date')->nullable(); // The date the SMS is about
            $table->string('content_hash')->nullable(); // For change detection
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Prevent duplicate sends for same channel/user/type/date
            $table->unique(['channel', 'user_id', 'type', 'target_date', 'project_id'], 'sms_unique');

            // Index for throttling queries
            $table->index(['channel', 'user_id', 'created_at'], 'sms_throttle');

            // Index for project-based queries
            $table->index(['project_id', 'channel', 'type'], 'sms_project');
        });

        // Drop old client-only table
        Schema::dropIfExists('client_schedule_sms_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');

        // Recreate old table if rolling back
        Schema::create('client_schedule_sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('target_date');
            $table->string('tasks_hash')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'type', 'target_date'], 'client_sms_unique');
            $table->index(['project_id', 'user_id', 'type', 'created_at'], 'client_sms_throttle');
        });
    }
};
