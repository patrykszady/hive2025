<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a photo came from when that someone isn't a Hive user — a client
     * texting pictures, mostly. Frames taken in-app keep using
     * taken_by_user_id; this is the display fallback for imports.
     */
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->string('taken_by_name', 120)->nullable()->after('taken_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropColumn('taken_by_name');
        });
    }
};
