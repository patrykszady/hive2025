<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('nylas_message_id')->index();
            $table->string('nylas_thread_id')->nullable()->index();
            $table->string('event_type'); // 'opened' or 'link_clicked'
            $table->string('recipient_email');
            $table->string('link_url')->nullable(); // For link_clicked events
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable(); // Store additional webhook data
            $table->timestamp('event_at');
            $table->timestamps();

            $table->index(['project_id', 'event_type']);
            $table->index(['nylas_message_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_tracking');
    }
};
