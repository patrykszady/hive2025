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
        Schema::create('sms_thread_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('sms_group_threads')->cascadeOnDelete();
            $table->string('phone_number');
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'phone_number']);
            $table->index('phone_number');
        });

        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->timestamp('opt_in_prompt_sent_at')->nullable()->after('last_activity_at');
            $table->timestamp('welcome_sent_at')->nullable()->after('opt_in_prompt_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->dropColumn(['opt_in_prompt_sent_at', 'welcome_sent_at']);
        });

        Schema::dropIfExists('sms_thread_participants');
    }
};
