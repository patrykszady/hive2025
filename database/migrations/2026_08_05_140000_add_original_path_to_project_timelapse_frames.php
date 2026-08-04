<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three copies of every frame, each with a job:
 *
 *   original_path  exactly what the camera produced — full resolution, never
 *                  resized or re-encoded. This is the archive copy.
 *   path           the sequence copy: oriented and capped so playback stays
 *                  uniform and light. Alignment reads this.
 *   aligned_path   the registered copy the timelapse actually plays.
 *
 * Nullable: frames shot before this existed have no untouched original, and
 * the controller falls back to `path` for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->string('original_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn('original_path');
        });
    }
};
