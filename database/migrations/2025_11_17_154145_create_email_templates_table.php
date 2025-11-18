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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vendor_id');
            $table->string('name'); // Template name (e.g., "Standard Estimate", "Detailed Estimate")
            $table->string('type')->default('estimate'); // estimate, invoice, etc.
            $table->string('subject');
            $table->text('body'); // Email body with placeholders
            $table->timestamps();
            
            $table->index(['vendor_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
