<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permit packet of a project (2026-10-26): the cover letter GS puts in
 * front of every plan set (title, who it is prepared for, contents, the
 * village's adopted codes), the plans and the engineer's notes uploaded for
 * it, which estimate is the Scope of Work & Contract, and the merged PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_packets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('estimate_id')->nullable();
            $table->string('title');
            $table->string('jurisdiction', 120)->nullable();
            $table->json('codes')->nullable();
            $table->boolean('include_contract')->default(true);
            $table->string('plans_path')->nullable();
            $table->string('plans_name')->nullable();
            $table->string('notes_path')->nullable();
            $table->string('notes_name')->nullable();
            $table->string('packet_path')->nullable();
            $table->string('packet_name')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('generated_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_packets');
    }
};
