<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove duplicate vendor↔company links (keeping the oldest row per pair)
     * and add a unique index so the vendors index can't show the same vendor
     * twice and future attaches can't duplicate.
     */
    public function up(): void
    {
        // Driver-agnostic dedupe (tests run on sqlite): keep the oldest row per pair.
        DB::table('vendors_vendor')
            ->select(DB::raw('MIN(id) as keep_id'), 'vendor_id', 'belongs_to_vendor_id')
            ->groupBy('vendor_id', 'belongs_to_vendor_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function ($duplicate) {
                DB::table('vendors_vendor')
                    ->where('vendor_id', $duplicate->vendor_id)
                    ->where('belongs_to_vendor_id', $duplicate->belongs_to_vendor_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();
            });

        Schema::table('vendors_vendor', function (Blueprint $table) {
            $table->unique(['vendor_id', 'belongs_to_vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('vendors_vendor', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'belongs_to_vendor_id']);
        });
    }
};
