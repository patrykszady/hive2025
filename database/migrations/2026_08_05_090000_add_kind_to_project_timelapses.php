<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project keeps several image COLLECTIONS: some are timelapses (onion-skin
 * capture, registration, playback), some are plain photo albums. Same table —
 * they differ only in whether frames are treated as a sequence.
 *
 * display_mode already exists and drives the public gs.construction site's
 * rendering, so it is left alone; `kind` is ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->string('kind', 20)->default('timelapse')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
