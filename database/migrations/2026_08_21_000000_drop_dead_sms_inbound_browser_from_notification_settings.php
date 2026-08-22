<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_settings.sms_inbound_browser was written and never read.
 *
 * The visible "Incoming Texts" toggle is per BROWSER — Alpine posts it to
 * /push/preferences, which stores push_subscriptions.sms_inbound_enabled,
 * and that is the only flag the send jobs consult. This per-USER copy was
 * also persisted by the settings component, drifted from the real one (the
 * blade never bound it, so saves re-wrote the value seen at mount), and
 * misled anyone reading the schema into gating on the wrong flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn('sms_inbound_browser');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('sms_inbound_browser')->default(false)->after('realtime_sms');
        });
    }
};
