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
        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->json('receipt_items')->after('receipt_html')->nullable();
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('receipt_width');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->dropColumn('receipt_items');
        });
    }
};
