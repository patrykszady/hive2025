<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every EWCCV lookup attempt, kept for review: what was searched (name and
 * address variants), what EWCCV listed, how it compared with the policy on
 * file, and the outcome. The doc's options only hold the last attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ewccv_lookup_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('vendor_doc_id')->nullable()->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->string('kind', 20);
            $table->date('coverage_date')->nullable();
            $table->string('outcome', 20)->index();
            $table->string('reason', 120)->nullable();
            $table->string('our_policy_number', 60)->nullable();
            $table->string('matched_name', 160)->nullable();
            $table->string('matched_policy', 60)->nullable();
            $table->string('match_type', 40)->nullable();
            $table->decimal('score', 4, 2)->nullable();
            $table->json('searched')->nullable();
            $table->json('candidates')->nullable();
            $table->unsignedSmallInteger('session_age_minutes')->nullable();
            $table->timestamp('run_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ewccv_lookup_logs');
    }
};
