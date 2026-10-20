<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email with a project's stakeholders (client users, vendors on the
     * job), read from the company's connected mailboxes in both directions,
     * so calls, texts and email sit in one timeline. One row per message
     * (keyed by the sha1 of its RFC Message-ID, however many inboxes it
     * reached), with its real attachments on the files disk and the same AI
     * read a call transcript gets.
     */
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->string('message_key', 64);
            $table->string('nylas_message_id')->nullable();
            $table->string('grant_id')->nullable();
            $table->string('mailbox')->nullable();
            $table->string('thread_id')->nullable()->index();
            $table->string('rfc_message_id', 998)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->string('direction', 8);
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('snippet', 500)->nullable();
            $table->longText('body_text')->nullable();
            $table->timestamp('sent_at')->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->json('candidate_project_ids')->nullable();
            $table->json('attachments')->nullable();
            $table->string('summary_model')->nullable();
            $table->text('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->json('topics')->nullable();
            $table->json('next_steps')->nullable();
            $table->string('sentiment', 16)->nullable();
            $table->string('intent')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();

            $table->unique(['belongs_to_vendor_id', 'message_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
