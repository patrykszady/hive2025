<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every line of every sub document compared on an estimate, on its own
     * row: what the sub listed, at what price, and which of our catalog
     * items it was matched to — a price and scope history per sub.
     */
    public function up(): void
    {
        Schema::create('estimate_sub_document_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estimate_sub_document_id')->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->unsignedBigInteger('sub_vendor_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedInteger('index');
            $table->string('description', 500);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 40)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedBigInteger('line_item_id')->nullable();
            $table->string('match_source', 16)->nullable();
            $table->boolean('applied')->default(false);
            $table->date('document_date')->nullable();
            $table->timestamps();
            $table->index(['sub_vendor_id', 'line_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_sub_document_items');
    }
};
