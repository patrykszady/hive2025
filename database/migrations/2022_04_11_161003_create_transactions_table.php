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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->date('posted_date')->nullable();
            $table->decimal('amount');
            $table->integer('bank_account_id')->unsigned();
            $table->integer('vendor_id')->unsigned()->nullable();
            $table->integer('expense_id')->unsigned()->nullable();
            $table->integer('check_id')->unsigned()->nullable();
            $table->string('check_number')->nullable();
            $table->integer('deposit')->unsigned()->nullable();

            //old plaid_merchant_name is now plaid_merchant_description
            $table->string('plaid_merchant_name')->nullable();
            $table->string('plaid_merchant_description')->nullable();
            $table->string('plaid_transaction_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction');
    }
};
