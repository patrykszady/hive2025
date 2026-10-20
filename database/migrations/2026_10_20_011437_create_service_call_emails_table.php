<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per client email the service-call intake judged, keyed by the
     * same sha1-of-Message-ID both mail readers use, so an email read from
     * two inboxes, by the sweep and the webhook, or by gs.construction first
     * is decided — and answered — exactly once.
     */
    public function up(): void
    {
        Schema::create('service_call_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_key', 64)->unique();
            $table->string('nylas_message_id')->nullable();
            $table->string('thread_id')->nullable()->index();
            $table->string('mailbox')->nullable();
            $table->string('from_email');
            $table->string('subject', 500)->nullable();
            $table->string('outcome', 32)->index();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('reason')->nullable();
            $table->json('verdict')->nullable();
            $table->foreignId('client_id')->nullable();
            $table->foreignId('project_id')->nullable()->index();
            $table->foreignId('task_id')->nullable()->index();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('texted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_call_emails');
    }
};
