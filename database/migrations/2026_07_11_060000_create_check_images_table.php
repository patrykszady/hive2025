<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scanned check images cropped from bank-statement PDFs (storage/files/checks/),
     * with the Azure Content Understanding analyzer payload and their resolved
     * links to checks / transactions.
     */
    public function up(): void
    {
        Schema::create('check_images', function (Blueprint $table) {
            $table->id();

            // Provenance — filename is the natural unique key (deterministic per check).
            $table->string('image_filename')->unique();
            $table->string('statement_filename')->nullable();

            // Resolved links. Soft-deleted checks never fire nullOnDelete —
            // CheckObserver clears check_id on soft delete instead.
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('check_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('belongs_to_vendor_id')->nullable()->index();

            // Match keys (bank-authoritative values from the statement caption).
            $table->unsignedInteger('check_number')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->date('check_date')->nullable()->comment('Cleared date from the statement caption');
            $table->string('account_number')->nullable()->comment('Full payer account digits (MICR preferred, statement header fallback)');
            $table->string('payee')->nullable();

            // Normalized analyzer output (AnalyzeCheckImage::fieldValue shape) —
            // raw CU responses with source polygons stay on disk (checks/files/).
            $table->json('check_fields')->nullable();
            $table->string('analyzer_id')->nullable();
            $table->timestamp('analyzed_at')->nullable();

            $table->string('match_status')->default('pending');
            $table->json('match_details')->nullable();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();

            $table->index(['bank_account_id', 'check_number']);
            $table->index(['check_number', 'amount']);
            $table->index('match_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_images');
    }
};
