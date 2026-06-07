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
        Schema::create('line_item_allowances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('line_item_id');
            $table->string('description');
            $table->decimal('unit_amount', 10, 2)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->unsignedBigInteger('belongs_to_vendor_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('line_item_id')
                ->references('id')
                ->on('line_items')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('line_item_allowances');
    }
};
