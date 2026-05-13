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
        Schema::create('lien_waivers', function (Blueprint $table) {
            $table->id();

            // The contractor (payer) that owns this record (multi-tenant scope)
            $table->integer('belongs_to_vendor_id')->index();

            // The vendor / subcontractor / supplier that the waiver is being collected from
            $table->integer('vendor_id')->index();

            // The project / job site the waiver applies to
            $table->integer('project_id')->index();

            // Optional links to the underlying payment artifacts
            $table->integer('check_id')->nullable()->index();
            $table->integer('payment_id')->nullable()->index();

            // Type: conditional_progress | unconditional_progress | conditional_final | unconditional_final
            $table->string('type', 32);

            // Status: draft | sent | signed | cancelled
            $table->string('status', 16)->default('draft')->index();

            // Money fields
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('exceptions_amount', 12, 2)->default(0);

            // Date through which the waiver applies (typically the payment date)
            $table->date('through_date');

            // Statutory jurisdiction the form was rendered for ('US-GENERIC', 'CA', 'IL', 'TX', ...)
            $table->string('jurisdiction', 16)->default('US-GENERIC');

            // Hash of the rendered document for tamper detection
            $table->string('document_hash', 64)->nullable();

            // Stored PDF paths on the 'files' disk
            $table->string('draft_path')->nullable();
            $table->string('signed_path')->nullable();

            $table->text('notes')->nullable();

            // Lifecycle timestamps
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();

            // Public token for emailed signing links
            $table->string('access_token', 64)->nullable()->unique();

            $table->integer('created_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lien_waivers');
    }
};
