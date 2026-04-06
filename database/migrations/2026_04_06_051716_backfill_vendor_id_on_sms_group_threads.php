<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $vendorId = DB::table('vendors')->where('id', 1)->value('id');

        if ($vendorId) {
            DB::table('sms_group_threads')
                ->whereNull('vendor_id')
                ->update(['vendor_id' => $vendorId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible — we don't know which threads originally had null vendor_id
    }
};
