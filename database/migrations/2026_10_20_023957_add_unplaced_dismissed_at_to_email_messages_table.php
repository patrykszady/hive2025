<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An email the AI could not place on a project waits in the unplaced
     * inbox until an admin assigns it, or dismisses it as not about any
     * project (this timestamp).
     */
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('unplaced_dismissed_at')->nullable()->after('summarized_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('unplaced_dismissed_at');
        });
    }
};
