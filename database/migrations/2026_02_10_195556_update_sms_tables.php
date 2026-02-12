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
        // Add thread_id, status, and media_urls to sms_messages
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->after('id')->constrained('sms_group_threads')->nullOnDelete();
            $table->string('status')->nullable()->after('raw_payload')->index();
            $table->json('media_urls')->nullable()->after('text');
        });

        // Add client_id to sms_group_threads
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropColumn(['thread_id', 'status', 'media_urls']);
        });
    }
};
