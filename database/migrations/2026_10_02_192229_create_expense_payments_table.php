<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How each expense was paid, one row per tender on its receipt: a card
     * (matched to a bank charge), or store credit, a gift card, points or
     * cash, which no bank charge will ever show.
     */
    public function up(): void
    {
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('expense_receipt_id')->nullable();
            $table->string('method', 32);
            $table->decimal('amount', 12, 2);
            $table->string('last_four', 4)->nullable();
            $table->string('brand', 32)->nullable();
            $table->date('paid_at')->nullable();
            $table->string('source', 32);
            $table->string('source_ref')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->timestamps();

            $table->index('expense_id');
            $table->index('transaction_id');
            $table->index(['method', 'last_four']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
    }
};
