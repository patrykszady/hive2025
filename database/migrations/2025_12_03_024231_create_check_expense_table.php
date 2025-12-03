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
        Schema::create('check_expense', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('check_id');
            $table->unsignedBigInteger('expense_id');
            $table->timestamps();
            
            $table->unique(['check_id', 'expense_id']);
            $table->index('check_id');
            $table->index('expense_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_expense');
    }
};
