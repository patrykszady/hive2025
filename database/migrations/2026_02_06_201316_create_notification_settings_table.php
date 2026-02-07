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
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Realtime channel toggles
            $table->boolean('realtime_email')->default(true);
            $table->boolean('realtime_sms')->default(false);

            // Realtime "as-they-happen" window
            $table->time('realtime_start')->default('07:00');
            $table->time('realtime_end')->default('18:00');

            // Digest toggles per channel — morning
            $table->boolean('morning_email')->default(false);
            $table->boolean('morning_sms')->default(false);

            // Digest toggles per channel — evening
            $table->boolean('evening_email')->default(false);
            $table->boolean('evening_sms')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
