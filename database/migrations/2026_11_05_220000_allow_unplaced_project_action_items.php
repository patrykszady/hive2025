<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An action item the AI could not place on a job still belongs in the
 * planner (Patryk, 2026-11-05: "if we can't match it to a project, we need
 * to create a task without a project and show it in the planner as 'needs
 * a project'"). project_id becomes nullable; belongs_to_vendor_id says
 * whose planner an unplaced item shows in, since the project no longer can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_action_items', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
            $table->unsignedBigInteger('belongs_to_vendor_id')->nullable()->after('project_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('project_action_items', function (Blueprint $table) {
            $table->dropColumn('belongs_to_vendor_id');
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }
};
