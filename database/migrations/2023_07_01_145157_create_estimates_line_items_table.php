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
        Schema::create('estimate_line_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained();
            $table->foreignId('line_item_id')->constrained();
            $table->integer('section_id');
            $table->string('name');
            $table->string('category');
            $table->string('sub_category')->nullable();
            $table->string('unit_type')->nullable();
            $table->integer('quantity');
            $table->decimal('cost'); //per unit type if set
            $table->decimal('total');
            $table->string('desc')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_line_item');
    }
};
