<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resolved payee entity for a scanned check image: the linked check's
     * payee when available, otherwise fuzzy-matched from the handwritten
     * payee text against the company's users and vendors.
     */
    public function up(): void
    {
        Schema::table('check_images', function (Blueprint $table) {
            $table->unsignedBigInteger('payee_user_id')->nullable()->index()->after('payee');
            $table->unsignedBigInteger('payee_vendor_id')->nullable()->index()->after('payee_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('check_images', function (Blueprint $table) {
            $table->dropColumn(['payee_user_id', 'payee_vendor_id']);
        });
    }
};
