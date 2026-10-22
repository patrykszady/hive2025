<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cards a company's receipts show that are not the company's own (card
     * ••1854 paid two Amazon orders in March 2026 and is on none of GS
     * Construction's bank accounts). Their payments never reach the
     * company's bank, so they count as paid instead of being hunted for.
     */
    public function up(): void
    {
        Schema::create('outside_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->string('last_four', 4);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['belongs_to_vendor_id', 'last_four']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outside_cards');
    }
};
