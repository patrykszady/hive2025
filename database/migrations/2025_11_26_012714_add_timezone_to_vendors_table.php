<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('business_email');
        });
        
        // Set timezone only for hiveVendors (Sub vendors with completed registration)
        DB::table('vendors')
            ->where('business_type', 'Sub')
            ->whereJsonContains('registration->registered', true)
            ->update(['timezone' => 'America/Chicago']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
