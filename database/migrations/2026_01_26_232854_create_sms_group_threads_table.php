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
        Schema::create('sms_group_threads', function (Blueprint $table) {
            $table->id();
            $table->string('from_number'); // Our Telnyx number
            $table->json('participants'); // Array of phone numbers in E.164 format
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('telnyx_message_id')->nullable(); // Original message ID
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('from_number');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_group_threads');
    }
};
