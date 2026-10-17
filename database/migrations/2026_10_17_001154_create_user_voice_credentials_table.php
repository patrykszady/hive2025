<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A team member's calling login for the Hive Contractors app (a Telnyx
     * telephony credential on the app connection). Hive rings them at
     * sip:<sip_username>@sip.telnyx.com and mints short-lived sign-in
     * tokens for it; the SIP password never leaves Telnyx.
     */
    public function up(): void
    {
        Schema::create('user_voice_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('telnyx_credential_id')->unique();
            $table->string('sip_username');
            $table->timestamp('last_token_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_voice_credentials');
    }
};
