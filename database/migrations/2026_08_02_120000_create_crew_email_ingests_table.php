<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of every message read from the crew@gs.construction shared mailbox.
 *
 * Three jobs:
 *
 *  1. IDEMPOTENCY. `nylas_message_id` is unique, so a message can only ever be
 *     processed once no matter how many times the poller overlaps its own
 *     window. The existing receipts pipeline dedupes by MOVING messages out of
 *     the polled folder — deliberately not copied here, because crew@ is read
 *     by humans and relocating a prospect's email would break the people
 *     working the inbox.
 *
 *  2. AUDIT. Every message gets a row even when it is skipped, with the reason.
 *     "Why didn't this become a lead?" is answerable without re-reading the
 *     mailbox, and a mis-skip can be re-run by clearing `status`.
 *
 *  3. SAFETY NET. If lead creation fails the row survives with the error, so
 *     nothing is silently lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_email_ingests', function (Blueprint $table) {
            $table->id();

            // Observed Nylas ids are 68 chars; 191 leaves headroom and still
            // indexes under utf8mb4.
            $table->string('nylas_message_id', 191)->unique();

            // The RFC Message-ID header. Preferred dedupe identity because it
            // is stable across folder moves and identical no matter which
            // grant proxied the read.
            $table->string('rfc_message_id', 255)->nullable()->index();

            $table->string('grant_id', 64);
            $table->string('mailbox', 255);
            $table->string('thread_id', 191)->nullable()->index();

            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->json('recipients')->nullable();
            $table->string('subject', 512)->nullable();
            $table->timestamp('message_at')->nullable()->index();

            // Kept so a skip decision can be reviewed later without another
            // API call. Not the full body — that lives on the lead.
            $table->text('body_snippet')->nullable();

            // pending | lead | skipped | failed
            $table->string('status', 32)->index();
            $table->string('skip_reason', 64)->nullable();

            $table->boolean('is_lead')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('extraction_status', 32)->nullable();
            $table->text('error')->nullable();

            $table->foreignId('lead_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_email_ingests');
    }
};
