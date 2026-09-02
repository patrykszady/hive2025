<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * One-time data fix riding the deploy: park Estimate projects that are
 * still waiting on their consult (an upcoming "… | Consult" Meet task) in
 * the new Consult status. The command is idempotent — once parked they no
 * longer match — and the hourly projects:advance-past-consults run takes
 * over from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_status') || ! Schema::hasTable('tasks')) {
            return;
        }

        Artisan::call('projects:backfill-consult-status');
    }

    public function down(): void
    {
        // Data fix — nothing to roll back.
    }
};
