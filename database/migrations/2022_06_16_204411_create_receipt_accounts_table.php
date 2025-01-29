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
        Schema::create('receipt_accounts', function (Blueprint $table) {
            $table->id();
            $table->integer('vendor_id');
            $table->integer('belongs_to_vendor_id');
            $table->integer('project_id')->nullable();
            $table->integer('distribution_id')->nullable();
            $table->json('instructions')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_accounts');
    }
};
