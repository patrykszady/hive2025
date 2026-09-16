<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI estimate generator's memory: every draft it made and what the
 * estimator changed afterwards, the company's own estimating rules, and the
 * embeddings that find the past sections closest to a new enquiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_ai_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('estimate_sections')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('model')->nullable();
            $table->text('inquiry');
            $table->json('floorplan')->nullable();
            $table->text('reasoning')->nullable();
            $table->json('drafted_items');
            $table->json('usage')->nullable();
            $table->string('stop_reason')->nullable();
            // drafted → finished (kept) or discarded
            $table->string('status', 20)->default('drafted');
            // Snapshot taken when the estimate is signed.
            $table->json('final_items')->nullable();
            $table->json('corrections')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status', 'created_at']);
        });

        Schema::create('estimate_ai_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->text('text');
            // active | proposed | dismissed
            $table->string('status', 20)->default('active');
            // manual | proposed
            $table->string('source', 20)->default('manual');
            // One proposal per pattern per company, whatever became of it.
            $table->string('fingerprint', 120)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'fingerprint']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('estimate_section_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->unique()->constrained('estimate_sections')->cascadeOnDelete();
            $table->foreignId('vendor_id')->index();
            $table->string('model', 80);
            $table->string('text_hash', 64);
            $table->json('vector');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_section_embeddings');
        Schema::dropIfExists('estimate_ai_rules');
        Schema::dropIfExists('estimate_ai_drafts');
    }
};
