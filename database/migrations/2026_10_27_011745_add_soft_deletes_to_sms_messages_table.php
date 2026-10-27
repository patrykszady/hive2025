<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A text message can be removed from Hive (the ⋮ menu's Delete, 2026-10-26):
 * soft-deleted, with who removed it. The people it went to still have it —
 * an SMS cannot be unsent. A reaction or edit notice that goes because of a
 * text (deleted with it, or arriving after it was deleted) keeps which text
 * in deleted_with_message_id, so restoring the text brings exactly those back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('deleted_at');
            $table->unsignedBigInteger('deleted_with_message_id')->nullable()->after('deleted_by_user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['deleted_with_message_id']);
            $table->dropColumn(['deleted_at', 'deleted_by_user_id', 'deleted_with_message_id']);
        });
    }
};
