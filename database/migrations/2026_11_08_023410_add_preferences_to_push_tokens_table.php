<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The same four per-device preferences push_subscriptions carries for a
     * browser, so the users page's Notification Settings card can offer the
     * Hive Contractors app as a channel next to Email, SMS and Browser —
     * plus `enabled`, one switch per phone. A browser is switched off by
     * deleting its subscription; a phone cannot be, because the app
     * re-registers its token on every launch (see App\Http\Controllers\Api\
     * App\V1\PushTokenController::store()), so the off state lives on the
     * row. Defaults keep every phone registered before this migration
     * behaving exactly as it did: every push still goes out.
     */
    public function up(): void
    {
        Schema::table('push_tokens', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('device_name');
            $table->boolean('realtime_enabled')->default(true)->after('enabled');
            $table->boolean('morning_enabled')->default(true)->after('realtime_enabled');
            $table->boolean('evening_enabled')->default(true)->after('morning_enabled');
            $table->boolean('sms_inbound_enabled')->default(true)->after('evening_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_tokens', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'realtime_enabled', 'morning_enabled', 'evening_enabled', 'sms_inbound_enabled']);
        });
    }
};
