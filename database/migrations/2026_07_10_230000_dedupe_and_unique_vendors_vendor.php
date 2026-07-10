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
        DB::statement('
            DELETE t1 FROM vendors_vendor t1
            INNER JOIN vendors_vendor t2
                ON t1.vendor_id = t2.vendor_id
                AND t1.belongs_to_vendor_id = t2.belongs_to_vendor_id
                AND t1.id > t2.id
        ');

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
