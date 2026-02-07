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
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->boolean('realtime_enabled')->default(true)->after('auth');
            $table->boolean('morning_enabled')->default(true)->after('realtime_enabled');
            $table->boolean('evening_enabled')->default(true)->after('morning_enabled');
            $table->string('user_agent', 500)->nullable()->after('evening_enabled');
            $table->timestamp('last_seen_at')->nullable()->after('user_agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['realtime_enabled', 'morning_enabled', 'evening_enabled', 'user_agent', 'last_seen_at']);
        });
    }
};
