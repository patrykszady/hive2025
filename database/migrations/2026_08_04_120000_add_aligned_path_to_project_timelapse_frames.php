<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration output: the frame warped onto the sequence's first frame so
 * handheld shots line up exactly. Nullable — frame #1 is the anchor, and a
 * frame the aligner wasn't confident about keeps only its original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->string('aligned_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn('aligned_path');
        });
    }
};
