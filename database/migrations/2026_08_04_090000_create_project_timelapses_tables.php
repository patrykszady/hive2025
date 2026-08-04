<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same shape as the gs.construction site's project_timelapses /
 * project_timelapse_frames tables, so sequences shot here transfer to the
 * public site's before/after/timelapse sliders without translation. Hive
 * additions: taken_by_user_id (who shot the frame) — extra columns don't
 * break the shared shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_timelapses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Timelapse');
            $table->string('display_mode')->default('slider');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('project_id');
        });

        Schema::create('project_timelapse_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_timelapse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taken_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('path');
            $table->string('disk')->default('files');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_timelapse_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_timelapse_frames');
        Schema::dropIfExists('project_timelapses');
    }
};
