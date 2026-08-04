<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the photo was actually TAKEN.
 *
 * Storing re-encodes every upload (orientate + resize), which strips EXIF —
 * so unless the capture time is read before that and kept here, it is gone
 * for good. Backfilled to created_at for existing rows: honest for camera
 * captures (shot and uploaded seconds apart), wrong for anything uploaded
 * later from a phone's library, and unrecoverable either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->timestamp('shot_at')->nullable()->after('disk');
            $table->index(['project_timelapse_id', 'shot_at']);
        });

        \Illuminate\Support\Facades\DB::statement('UPDATE project_timelapse_frames SET shot_at = created_at WHERE shot_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropIndex(['project_timelapse_id', 'shot_at']);
            $table->dropColumn('shot_at');
        });
    }
};
