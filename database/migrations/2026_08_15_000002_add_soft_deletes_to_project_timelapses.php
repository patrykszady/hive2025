<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a timelapse from the studio is soft — the collection (and its
     * frames, which are left untouched) can be restored wholesale.
     */
    public function up(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
