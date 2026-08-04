<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two collections with the same name on one project is always a mistake —
 * usually a double-tapped "Create" on job-site LTE, which silently splits a
 * sequence in half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->unique(['project_id', 'title']);
        });
    }

    public function down(): void
    {
        Schema::table('project_timelapses', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'title']);
        });
    }
};
