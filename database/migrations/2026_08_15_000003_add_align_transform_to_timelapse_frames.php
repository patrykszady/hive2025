<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The transform that produced a frame's aligned copy — scale, turn and
     * pan relative to the 1920px preview the manual aligner shows.
     *
     * Without it the aligner opens every frame at 1:1 even though the
     * timelapse shows it zoomed, so a human re-does the whole fit by hand
     * and, worse, turning a 1:1 frame has no overflow to fill its corners
     * with. Stored, the modal opens on exactly what the sequence plays.
     */
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->json('align_transform')->nullable()->after('aligned_path');
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn('align_transform');
        });
    }
};
