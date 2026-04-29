<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expense_transaction', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('transaction_id');
            $table->timestamps();

            $table->unique(['expense_id', 'transaction_id']);
            $table->index('expense_id');
            $table->index('transaction_id');
        });

        // Migrate existing expense_id relationships from transactions table to pivot table.
        // Use CURRENT_TIMESTAMP for portability (NOW() is not available in sqlite).
        DB::statement("
            INSERT INTO expense_transaction (expense_id, transaction_id, created_at, updated_at)
            SELECT expense_id, id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM transactions
            WHERE expense_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_transaction');
    }
};
