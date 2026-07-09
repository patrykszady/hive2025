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
        Schema::table('users', function (Blueprint $table) {
            // Last time the user opened /messages — the sidebar badge only
            // counts unread messages newer than this, so it resets on visit
            // while per-thread unread indicators stay until each thread is read.
            $table->timestamp('sms_last_seen_at')->nullable()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_last_seen_at');
        });
    }
};
