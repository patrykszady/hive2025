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
        Schema::create('estimate_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('signer_name');
            $table->string('signer_email')->nullable();
            $table->text('signature_data');
            $table->enum('signature_type', ['draw', 'type'])->default('draw');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('document_hash', 64);
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_signatures');
    }
};
