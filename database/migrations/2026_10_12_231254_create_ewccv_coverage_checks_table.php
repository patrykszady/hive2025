<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per vendor, asking tenant and coverage date: did EWCCV list a
 * workers comp policy for this vendor on that date? The workers comp audit
 * asks it for every sub paid in the period, as of the audit's end date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ewccv_coverage_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->date('coverage_date');
            $table->string('status', 20)->default('pending')->index();
            $table->string('policy_number', 60)->nullable();
            $table->string('employer_name', 160)->nullable();
            $table->string('match_type', 40)->nullable();
            $table->string('reason', 120)->nullable();
            $table->json('details')->nullable();
            $table->json('searched')->nullable();
            $table->json('candidates')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'belongs_to_vendor_id', 'coverage_date'], 'ewccv_coverage_checks_vendor_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ewccv_coverage_checks');
    }
};
