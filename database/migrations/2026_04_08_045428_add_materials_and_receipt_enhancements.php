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
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('status')->index();
        });

        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->boolean('is_material_order')->default(false)->after('receipt_items');
        });

        Schema::create('receipt_line_item_descs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('expense_receipt_id');
            $table->foreign('expense_receipt_id')->references('id')->on('expense_receipts_data')->cascadeOnDelete();
            $table->unsignedInteger('item_index');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('area')->nullable();
            $table->string('product_url', 2048)->nullable();
            $table->string('product_image_url', 2048)->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors');
            $table->unique(['expense_receipt_id', 'item_index']);
            $table->index(['vendor_id', 'sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_line_item_descs');

        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->dropColumn('is_material_order');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
