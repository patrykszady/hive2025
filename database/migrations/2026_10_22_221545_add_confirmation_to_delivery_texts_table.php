<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A delivery text that asks to be confirmed ("Reply 1 to CONFIRM … if we
     * do not receive delivery confirmation, delivery will be canceled") is
     * answered with that reply once it is on a project; this records it.
     */
    public function up(): void
    {
        Schema::table('delivery_texts', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('delivery_date');
            $table->unsignedBigInteger('confirmation_sms_message_id')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_texts', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'confirmation_sms_message_id']);
        });
    }
};
