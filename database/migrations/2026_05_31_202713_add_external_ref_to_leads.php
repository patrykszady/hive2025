<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('external_source', 64)->nullable()->after('origin');
            $table->string('external_id', 64)->nullable()->after('external_source');
            // Per-vendor dedup so the same source can't push the same external_id twice.
            $table->unique(['belongs_to_vendor_id', 'external_source', 'external_id'], 'leads_vendor_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_vendor_external_unique');
            $table->dropColumn(['external_source', 'external_id']);
        });
    }
};
