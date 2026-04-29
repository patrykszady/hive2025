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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('manufacturer')->nullable();
            $table->string('mpn');
            $table->string('mpn_normalized')->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('product_url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('source', 32)->default('scrape');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['manufacturer', 'mpn']);
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
