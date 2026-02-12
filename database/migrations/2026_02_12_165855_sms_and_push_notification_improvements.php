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
        // Track per-user read position in SMS threads
        Schema::create('sms_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('sms_group_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'user_id']);
            $table->index('user_id');
        });

        // Allow browser push notifications for inbound SMS
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('sms_inbound_browser')->default(false)->after('realtime_sms');
        });

        // Store browser's preferred content encoding for push delivery
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('content_encoding', 20)->nullable()->after('auth');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_thread_reads');

        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('sms_inbound_browser');
        });

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('content_encoding');
        });
    }
};
