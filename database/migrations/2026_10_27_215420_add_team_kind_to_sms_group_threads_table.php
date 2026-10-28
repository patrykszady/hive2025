<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team threads (2026-10-27): messages between GS team members that live in
 * Hive only — never sent as texts. `kind` = 'team' marks one; `member_user_ids`
 * are its people (a pair, or the whole team). SMS threads keep kind 'sms'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_group_threads', function (Blueprint $table): void {
            $table->string('kind', 16)->default('sms')->index()->after('id');
            $table->json('member_user_ids')->nullable()->after('participants');
        });
    }

    public function down(): void
    {
        Schema::table('sms_group_threads', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'member_user_ids']);
        });
    }
};
