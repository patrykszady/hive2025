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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->integer('project_id');
            $table->float('amount');
            $table->date('date');
            $table->string('reference')->nullable();
            $table->integer('transaction_id')->nullable();
            $table->integer('belongs_to_vendor_id');
            $table->integer('parent_client_payment_id')->nullable();
            $table->string('note')->nullable();
            $table->integer('created_by_user_id'); //who created this.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_payments');
    }
};
