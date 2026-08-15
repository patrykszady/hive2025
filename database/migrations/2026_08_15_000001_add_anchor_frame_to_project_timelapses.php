<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which frame a timelapse aligns to. Historically always the FIRST frame —
 * but when the first shot was framed differently (too far away, wrong spot),
 * anchoring on it warps nothing and fails everything. Null keeps the old
 * behavior (first by sort order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->foreignId('anchor_frame_id')->nullable()->after('display_mode')
                ->constrained('project_timelapse_frames')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anchor_frame_id');
        });
    }
};
