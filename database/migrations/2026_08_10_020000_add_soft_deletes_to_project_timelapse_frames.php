<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            // Deleting a frame keeps its files and row recoverable — only a
            // force delete removes the stored copies.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapse_frames', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
