<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every field EWCCV returned for the employer a lookup matched, kept as-is
 * for comparison with the vendor on file (asked for after a one-word match
 * subscribed to the wrong business on 2026-10-13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ewccv_lookup_logs', function (Blueprint $table) {
            $table->json('details')->nullable()->after('candidates');
        });
    }

    public function down(): void
    {
        Schema::table('ewccv_lookup_logs', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
