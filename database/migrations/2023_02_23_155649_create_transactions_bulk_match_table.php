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
        Schema::create('transactions_bulk_match', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount')->nullable(); //if amount = NULL, find any amount
            $table->integer('vendor_id')->unsigned();
            $table->integer('distribution_id')->unsigned();
            $table->integer('belongs_to_vendor_id');
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unique('vendor_id');
        });

        Schema::table('user_vendor', function (Blueprint $table) {
            $table->decimal('hourly_rate')->default(0)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions_bulk_match');
    }
};
