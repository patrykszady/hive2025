<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audit report someone asked for: its period, type and bank accounts,
 * the EWCCV coverage date it is checked against (end date minus two days),
 * and whether its package (certificates PDF + Excel) has been emailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
            $table->string('signature', 64)->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('audit_type', 20)->nullable();
            $table->json('bank_account_ids');
            $table->date('coverage_date')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->json('vendors_checked')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->string('emailed_to', 160)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['belongs_to_vendor_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_requests');
    }
};
