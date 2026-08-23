<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a frame was taken. Camera captures get the browser's live GPS
     * fix; uploads get whatever EXIF the original carried. Texted photos
     * never have this — carriers strip it — which is exactly why the camera
     * page records it.
     */
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            // Not after('shot_at') — that column is added by the 10:00:00 migration
            // the same day, which sorts AFTER this one. Fresh databases replay these
            // in filename order and would hit an unknown-column error here.
            $table->decimal('latitude', 10, 7)->nullable()->after('disk');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            // Meters, straight from the geolocation fix — a 5m fix and a
            // 3km cell-tower fix should not read as equally trustworthy.
            $table->unsignedInteger('location_accuracy')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_accuracy']);
        });
    }
};
