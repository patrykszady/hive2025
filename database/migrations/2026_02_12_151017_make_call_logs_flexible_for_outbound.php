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
        Schema::table('call_logs', function (Blueprint $table) {
            // Drop the existing unique index first
            $table->dropUnique('call_logs_call_id_unique');
        });

        Schema::table('call_logs', function (Blueprint $table) {
            // Make call_id nullable for outbound click-to-call
            $table->string('call_id')->nullable()->change();
            // Make call_control_id nullable (same reason)
            $table->string('call_control_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('call_id')->nullable(false)->unique()->change();
            $table->string('call_control_id')->nullable(false)->change();
        });
    }
};
