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
        Schema::table('sms_thread_participants', function (Blueprint $table) {
            $table->string('manual_opt_in_reason')->nullable()->after('opted_in_at');
            $table->foreignId('manual_opt_in_by')->nullable()->after('manual_opt_in_reason')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_thread_participants', function (Blueprint $table) {
            $table->dropForeign(['manual_opt_in_by']);
            $table->dropColumn(['manual_opt_in_reason', 'manual_opt_in_by']);
        });
    }
};
