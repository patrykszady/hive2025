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
        if (Schema::hasColumn('sms_group_threads', 'subject_vendor_id')) {
            return;
        }

        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_vendor_id')->nullable()->after('vendor_id');
            $table->foreign('subject_vendor_id')->references('id')->on('vendors')->onDelete('set null');
            $table->index('subject_vendor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('sms_group_threads', 'subject_vendor_id')) {
            return;
        }

        Schema::table('sms_group_threads', function (Blueprint $table) {
            $table->dropForeign(['subject_vendor_id']);
            $table->dropColumn('subject_vendor_id');
        });
    }
};
