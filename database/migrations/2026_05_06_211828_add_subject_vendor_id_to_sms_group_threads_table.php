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
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->foreignId('subject_vendor_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('vendors')
                ->nullOnDelete();

            $table->index(['subject_vendor_id', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->dropIndex(['subject_vendor_id', 'last_activity_at']);
            $table->dropForeign(['subject_vendor_id']);
            $table->dropColumn('subject_vendor_id');
        });
    }
};
