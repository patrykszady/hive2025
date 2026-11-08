<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Expo push token per installed device. A reinstall issues a new
     * Expo token for the same physical device, so rows are keyed on `token`
     * (upserted), not on `user_id` — see App\Http\Controllers\Api\App\V1\
     * PushTokenController. `personal_access_token_id` ties a row to the
     * exact Sanctum device token that registered it, so signing out on one
     * device only deletes that device's push token (App\Http\Controllers\
     * Api\App\V1\AuthController::logout()).
     */
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('token')->unique();
            $table->string('platform');
            $table->string('device_name')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
