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
        Schema::create('expense_receipts_data', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('expense_id')->unsigned();
            $table->text('receipt_html')->nullable();
            $table->string('receipt_filename');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_receipts_data');
    }
};