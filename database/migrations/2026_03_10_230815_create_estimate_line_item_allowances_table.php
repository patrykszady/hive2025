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
        Schema::create('estimate_line_item_allowances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estimate_line_item_id');
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('estimate_line_item_id')
                ->references('id')
                ->on('estimate_line_item')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_line_item_allowances');
    }
};
