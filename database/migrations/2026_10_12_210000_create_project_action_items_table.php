<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The action tasks a project's phone calls and text messages add up to,
 * grouped when the same task comes up more than once, and the bookmark of
 * how far each conversation has been read (ProjectActionItemExtractor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('owner', 80)->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('mentions')->default(1);
            $table->timestamp('first_mentioned_at')->nullable();
            $table->timestamp('last_mentioned_at')->nullable();
            $table->json('sources')->nullable();
            $table->string('fingerprint', 64);
            $table->json('embedding')->nullable();
            $table->json('done_evidence')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('project_action_item_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('source_id')->default(0);
            $table->unsignedBigInteger('last_source_id')->default(0);
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'kind', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_action_item_scans');
        Schema::dropIfExists('project_action_items');
    }
};
