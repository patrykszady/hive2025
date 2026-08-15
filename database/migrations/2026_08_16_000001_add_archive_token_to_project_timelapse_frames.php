<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The archive original — the UNBLURRED, full-resolution, EXIF-bearing file —
 * gets its own unguessable address. Its old one was
 * /timelapse/frames/{id}?original=1: a sequential id plus a flag, so anyone
 * who could reach one frame could walk the whole table by counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->string('archive_token', 64)->nullable()->unique()->after('original_path');
        });

        DB::table('project_timelapse_frames')->whereNull('archive_token')->orderBy('id')
            ->chunkById(500, function ($frames) {
                foreach ($frames as $frame) {
                    DB::table('project_timelapse_frames')
                        ->where('id', $frame->id)
                        ->update(['archive_token' => Str::random(48)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn('archive_token');
        });
    }
};
