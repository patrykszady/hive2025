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
        Schema::table('receipt_line_item_descs', function (Blueprint $table) {
            $table->dropForeign(['expense_receipt_id']);
            $table->dropUnique(['expense_receipt_id', 'item_index']);

            $table->unsignedInteger('expense_receipt_id')->nullable()->change();
            $table->foreign('expense_receipt_id')->references('id')->on('expense_receipts_data')->nullOnDelete();
            $table->unique(['expense_receipt_id', 'item_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_line_item_descs', function (Blueprint $table) {
            $table->dropForeign(['expense_receipt_id']);
            $table->dropUnique(['expense_receipt_id', 'item_index']);

            $table->unsignedInteger('expense_receipt_id')->nullable(false)->change();
            $table->foreign('expense_receipt_id')->references('id')->on('expense_receipts_data')->cascadeOnDelete();
            $table->unique(['expense_receipt_id', 'item_index']);
        });
    }
};
