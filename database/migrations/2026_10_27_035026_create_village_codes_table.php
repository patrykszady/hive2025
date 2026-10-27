<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The building codes each village or city has adopted, as its own website
 * publishes them — read by App\Services\Permits\VillageCodeFinder when a
 * permit packet first needs a town, re-read on the first of every month
 * (permits:refresh-village-codes). One row per town.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('village_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('town', 120);
            $table->string('state', 2)->default('IL');
            $table->string('status', 20)->default('pending')->index();
            $table->string('found_by', 10)->default('ai');
            $table->string('source_url', 500)->nullable();
            $table->json('codes')->nullable();
            $table->json('previous_codes')->nullable();
            $table->string('note', 300)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->unique(['town', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('village_codes');
    }
};
